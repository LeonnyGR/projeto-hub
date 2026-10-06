<?php

declare(strict_types=1);

namespace App\Transportadoras;

use App\Exceptions\ErroDeNegocio;
use App\Models\CredencialTransportadora;
use App\Support\Dinheiro;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Integracao com os Correios (PDF "Manual de Integracao Correios API", v2.4).
 *
 * Autenticacao em 2 passos:
 *  1. POST /token/v1/autentica/cartaopostagem com usuario/senha (Basic) e o cartao de postagem => token (vale ~24h);
 *  2. demais chamadas com "Authorization: Bearer <token>".
 * O token fica no cache ate perto de vencer.
 *
 * Preco e prazo sao duas APIs separadas, entao cotar = 2 tipos de chamada (as duas vao em paralelo).
 */
class Correios implements Transportadora
{
    public static function regras(): array
    {
        return [
            'credenciais' => ['required', 'array'],
            'credenciais.usuario' => ['required', 'string', 'max:100'],
            'credenciais.senha' => ['required', 'string', 'max:200'],
            'credenciais.cartao_postagem' => ['required', 'string', 'digits_between:8,12'],

            'configuracao' => ['required', 'array'],
            'configuracao.contrato' => ['nullable', 'string', 'max:20'],
            // Servicos contratados (codigo de 5 digitos, como 03220 SEDEX e 04669 PAC nos exemplos do PDF)
            'configuracao.servicos' => ['required', 'array', 'min:1', 'max:20'],
            'configuracao.servicos.*.codigo' => ['required', 'string', 'regex:/^\d{5}$/'],
            'configuracao.servicos.*.nome' => ['required', 'string', 'max:60'],
        ];
    }

    public static function perguntas(): array
    {
        return [
            ['campo' => 'credenciais.usuario', 'rotulo' => 'Usuario do Meu Correios'],
            ['campo' => 'credenciais.senha', 'rotulo' => 'Senha/codigo de acesso a API dos Correios', 'secreto' => true],
            ['campo' => 'credenciais.cartao_postagem', 'rotulo' => 'Numero do cartao de postagem', 'secreto' => true],
            ['campo' => 'configuracao.contrato', 'rotulo' => 'Numero do contrato (Enter se nao tiver)'],
            ['campo' => 'configuracao.servicos', 'rotulo' => 'Servicos contratados, no formato codigo:nome,codigo:nome (ex.: 03220:SEDEX,04669:PAC)', 'tipo' => 'servicos'],
        ];
    }

    // Limite de itens por chamada em lote de preco (PDF: "ate 5 simulacoes").
    // Para prazo o PDF nao informa limite; usamos o mesmo por seguranca.
    private const MAX_ITENS_POR_CHAMADA = 5;

    // 2 = pacote/caixa (valor usado nos exemplos do PDF).
    private const TIPO_OBJETO_PACOTE = '2';

    public function __construct(
        private readonly CredencialTransportadora $credencial,
        private readonly ClienteHttp $http,
        // Seller em nome de quem estamos chamando (so para o historico de chamadas).
        private readonly ?string $sellerId = null,
    ) {
    }

    public function codigo(): string
    {
        return 'correios';
    }

    public function pedidosDeCotacao(array $dados): array
    {
        $cabecalhos = $this->cabecalhosComToken();
        $base = config('transportadoras.correios.url');
        $hoje = now()->format('d/m/Y');

        $pedidos = [];

        // Prazo: 1 item por servico.
        $itensPrazo = [];
        foreach (array_keys($this->servicos()) as $i => $codigo) {
            $itensPrazo[] = [
                'cepDestino' => $dados['cep_destino'],
                'cepOrigem' => $dados['cep_origem'],
                'coProduto' => (string) $codigo,
                'nuRequisicao' => (string) $i,
                'dtEvento' => $hoje,
            ];
        }
        foreach (array_chunk($itensPrazo, self::MAX_ITENS_POR_CHAMADA) as $n => $grupo) {
            $pedidos[] = [
                'chave' => "prazo-{$n}",
                'metodo' => 'POST',
                'url' => $base.'/prazo/v1/nacional',
                'headers' => $cabecalhos,
                'corpo' => ['idLote' => '1', 'parametrosPrazo' => $grupo],
            ];
        }

        // Preco: o preco e por objeto, entao 1 item por servico E por volume (depois somamos).
        $itensPreco = [];
        foreach ($this->entradasDePreco($dados) as $numero => $entrada) {
            $volume = $entrada['volume'];
            $itensPreco[] = [
                'coProduto' => (string) $entrada['servico'],
                'nuRequisicao' => (string) $numero,
                'cepOrigem' => $dados['cep_origem'],
                'cepDestino' => $dados['cep_destino'],
                'psObjeto' => (string) $volume['peso_gramas'],
                'tpObjeto' => self::TIPO_OBJETO_PACOTE,
                'comprimento' => (string) $volume['comprimento_cm'],
                'largura' => (string) $volume['largura_cm'],
                'altura' => (string) $volume['altura_cm'],
                'vlDeclarado' => Dinheiro::paraDecimal($dados['valor_declarado_centavos']),
                'dtEvento' => $hoje,
            ];
        }
        foreach (array_chunk($itensPreco, self::MAX_ITENS_POR_CHAMADA) as $n => $grupo) {
            $pedidos[] = [
                'chave' => "preco-{$n}",
                'metodo' => 'POST',
                'url' => $base.'/preco/v1/nacional',
                'headers' => $cabecalhos,
                'corpo' => ['idLote' => '1', 'parametrosProduto' => $grupo],
            ];
        }

        return $pedidos;
    }

    public function lerCotacao(array $dados, array $respostas): array
    {
        $prazos = [];      // [codigo do servico => dias]
        $precos = [];      // [codigo do servico => [numero da entrada => centavos]]
        $entradas = $this->entradasDePreco($dados);
        $respostaValida = false;
        $semCobertura = false;
        $motivo = 'sem_resposta';

        foreach ($respostas as $chave => $resposta) {
            $problema = TransportadoraIndisponivel::motivoDaResposta($resposta);
            if ($problema !== null) {
                $motivo = $problema;
                if ($problema === 'credencial_invalida') {
                    $this->esquecerToken(); // o token pode ter vencido: renova na proxima vez
                }

                continue;
            }

            $json = $resposta->json();
            if (! $resposta->successful() || ! is_array($json)) {
                // 4xx com mensagem dos Correios (ex.: CEP invalido) = sem opcao, nao e falha da transportadora.
                if (is_array($json) && (isset($json['msgs']) || isset($json['txMsg']))) {
                    $respostaValida = true;
                    $semCobertura = true;
                } else {
                    $motivo = 'resposta_invalida';
                }

                continue;
            }

            $respostaValida = true;

            foreach ($json as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (str_starts_with((string) $chave, 'prazo') && isset($item['coProduto'], $item['prazoEntrega'])) {
                    $prazos[(string) $item['coProduto']] = (int) $item['prazoEntrega'];
                }

                if (str_starts_with((string) $chave, 'preco') && isset($item['nuRequisicao'], $item['pcFinal'])) {
                    $numero = (int) $item['nuRequisicao'];
                    if (isset($entradas[$numero])) {
                        $precos[$entradas[$numero]['servico']][$numero] = Dinheiro::deTextoBrasileiro((string) $item['pcFinal']);
                    }
                }
            }
        }

        if (! $respostaValida) {
            throw new TransportadoraIndisponivel($motivo);
        }

        // So oferecemos o servico se temos o prazo E o preco de TODOS os volumes.
        $opcoes = [];
        foreach ($this->servicos() as $codigo => $nome) {
            $codigo = (string) $codigo;
            $volumesEsperados = count($dados['volumes']);

            if (! isset($prazos[$codigo]) || count($precos[$codigo] ?? []) !== $volumesEsperados) {
                continue;
            }

            $opcoes[] = [
                'servico_codigo' => $codigo,
                'servico_nome' => $nome,
                'valor_centavos' => array_sum($precos[$codigo]),
                'prazo_dias' => $prazos[$codigo],
            ];
        }

        // Os Correios responderam com erro de negocio (ex.: CEP invalido ou sem atendimento) e nao ha nenhuma opcao.
        if ($opcoes === [] && $semCobertura) {
            throw new TransportadoraIndisponivel('sem_cobertura');
        }

        return $opcoes;
    }

    /**
     * Cria uma pre-postagem POR VOLUME (cada volume e um objeto dos Correios, com codigo de rastreio proprio).
     * Se um volume falhar depois de outros terem sido criados, cancela os que ja existem.
     */
    public function criarRemessa(array $remessa): array
    {
        $criados = [];

        try {
            foreach ($remessa['volumes'] as $volume) {
                $criados[] = $this->criarPrePostagem($remessa, $volume);
            }
        } catch (\Throwable $e) {
            foreach ($criados as $criado) {
                try {
                    $this->cancelarPrePostagem($criado['id_externo']);
                } catch (\Throwable) {
                    // melhor esforco: o erro original e o que importa
                }
            }

            throw $e;
        }

        return [
            'id_externo' => $criados[0]['id_externo'],
            'codigo_rastreio' => $criados[0]['codigo_rastreio'],
            'volumes' => $criados,
            // A etiqueta dos Correios e gerada depois, sob demanda (ver etiqueta()).
            'etiqueta_pdf' => null,
        ];
    }

    public function cancelarRemessa(array $remessa): void
    {
        foreach ($remessa['volumes'] as $volume) {
            $this->cancelarPrePostagem($volume['id_externo']);
        }
    }

    /**
     * Etiqueta (rotulo de enderecamento) de todos os volumes, num unico PDF.
     * A geracao e assincrona: pedimos, e depois baixamos pelo numero do recibo.
     */
    public function etiqueta(array $remessa): ?string
    {
        $base = config('transportadoras.correios.url').'/prepostagem/v1/prepostagens/rotulo';
        $ids = array_column($remessa['volumes'], 'id_externo');

        $pedido = $this->http->enviar($this->sellerId, 'correios', 'etiqueta', [
            'metodo' => 'POST',
            'url' => $base.'/assincrono/pdf',
            'headers' => $this->cabecalhosComToken(),
            // P = rotulo padrao, ET = etiqueta (valores do PDF)
            'corpo' => ['idsPrePostagem' => $ids, 'tipoRotulo' => 'P', 'formatoRotulo' => 'ET'],
        ]);

        $json = $this->lerRespostaOuFalhar($pedido);
        $recibo = $json['idRecibo'] ?? null;
        if (! is_string($recibo) || $recibo === '') {
            throw new TransportadoraIndisponivel('resposta_invalida');
        }

        // Tenta baixar algumas vezes: o PDF pode demorar um instante para ficar pronto.
        for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
            $download = $this->http->enviar($this->sellerId, 'correios', 'etiqueta_download', [
                'metodo' => 'GET',
                'url' => $base.'/download/assincrono/'.$recibo,
                'headers' => $this->cabecalhosComToken(),
            ]);

            if ($download !== null && $download->successful() && is_array($download->json()) && ! empty($download->json('dados'))) {
                $pdf = base64_decode((string) $download->json('dados'), true);

                return $pdf !== false && str_starts_with($pdf, '%PDF') ? $pdf : null;
            }

            if ($tentativa < 3) {
                usleep(700_000);
            }
        }

        return null;
    }

    private function criarPrePostagem(array $remessa, array $volume): array
    {
        $corpo = [
            'remetente' => $this->pessoa($remessa['remetente']),
            'destinatario' => $this->pessoa($remessa['destinatario']),
            'codigoServico' => $remessa['servico_codigo'],
            'codigoFormatoObjetoInformado' => self::TIPO_OBJETO_PACOTE,
            'pesoInformado' => (string) $volume['peso_gramas'],
            'alturaInformada' => (string) $volume['altura_cm'],
            'larguraInformada' => (string) $volume['largura_cm'],
            'comprimentoInformado' => (string) $volume['comprimento_cm'],
        ];

        $doc = $remessa['documento_fiscal'];
        if ($doc !== null && in_array($doc['tipo'], ['nf', 'nfe'], true)) {
            $corpo['numeroNotaFiscal'] = $doc['numero'] ?? null;
            $corpo['chaveNFe'] = $doc['chave'] ?? null;
        }

        $resposta = $this->http->enviar($this->sellerId, 'correios', 'criar_remessa', [
            'metodo' => 'POST',
            'url' => config('transportadoras.correios.url').'/prepostagem/v1/prepostagens',
            'headers' => $this->cabecalhosComToken(),
            'corpo' => $corpo,
        ]);

        $problema = TransportadoraIndisponivel::motivoDaResposta($resposta);
        if ($problema !== null) {
            if ($problema === 'credencial_invalida') {
                $this->esquecerToken();
            }

            throw new TransportadoraIndisponivel($problema);
        }

        $json = $resposta->json();

        // 4xx com mensagem: os Correios validaram os dados e recusaram.
        if ($resposta->clientError() && is_array($json)) {
            throw new ErroDeNegocio('remessa_recusada', 'A transportadora recusou a remessa.', 422, ['motivo' => 'recusada']);
        }

        // O PDF mostra o "id" da pre-postagem (PR...). O numero de rastreio (codigoObjeto) nao aparece na tela do PDF.
        if (! $resposta->successful() || ! is_array($json) || empty($json['id']) || empty($json['codigoObjeto'])) {
            throw new TransportadoraIndisponivel('resposta_invalida');
        }

        return ['id_externo' => (string) $json['id'], 'codigo_rastreio' => (string) $json['codigoObjeto']];
    }

    private function cancelarPrePostagem(string $id): void
    {
        $resposta = $this->http->enviar($this->sellerId, 'correios', 'cancelar_remessa', [
            'metodo' => 'DELETE',
            'url' => config('transportadoras.correios.url').'/prepostagem/v1/prepostagens/'.$id,
            'headers' => $this->cabecalhosComToken(),
        ]);

        $problema = TransportadoraIndisponivel::motivoDaResposta($resposta);
        if ($problema !== null) {
            if ($problema === 'credencial_invalida') {
                $this->esquecerToken();
            }

            throw new TransportadoraIndisponivel($problema);
        }

        // 404 = a pre-postagem nao existe mais (ja cancelada): o resultado que queremos ja existe.
        if ($resposta->successful() || $resposta->status() === 404) {
            return;
        }

        if ($resposta->clientError()) {
            throw new ErroDeNegocio('cancelamento_recusado', 'A transportadora recusou o cancelamento.', 422);
        }

        throw new TransportadoraIndisponivel('resposta_invalida');
    }

    /** Resposta JSON de sucesso, ou excecao. */
    private function lerRespostaOuFalhar(?\Illuminate\Http\Client\Response $resposta): array
    {
        $problema = TransportadoraIndisponivel::motivoDaResposta($resposta);
        if ($problema !== null) {
            throw new TransportadoraIndisponivel($problema);
        }

        $json = $resposta->json();
        if (! $resposta->successful() || ! is_array($json)) {
            throw new TransportadoraIndisponivel('resposta_invalida');
        }

        return $json;
    }

    /** Pessoa no formato da pre-postagem (tela do PDF): telefone e celular separados em DDD e numero. */
    private function pessoa(array $p): array
    {
        $ddd = substr($p['telefone'], 0, 2);
        $numero = substr($p['telefone'], 2);
        $fixo = strlen($p['telefone']) === 10;

        return [
            'nome' => $p['nome'],
            'dddTelefone' => $fixo ? $ddd : '',
            'telefone' => $fixo ? $numero : '',
            'dddCelular' => $fixo ? '' : $ddd,
            'celular' => $fixo ? '' : $numero,
            'email' => $p['email'] ?? '',
            'cpfCnpj' => $p['documento'],
            'endereco' => [
                'cep' => $p['endereco']['cep'],
                'logradouro' => $p['endereco']['logradouro'],
                'numero' => $p['endereco']['numero'],
                'complemento' => $p['endereco']['complemento'] ?? '',
                'bairro' => $p['endereco']['bairro'],
                'cidade' => $p['endereco']['cidade'],
                'uf' => $p['endereco']['uf'],
            ],
        ];
    }

    /** ['03220' => 'SEDEX', '04669' => 'PAC'] a partir da configuracao do seller. */
    private function servicos(): array
    {
        return Transportadoras::servicosDaConfiguracao($this->credencial->configuracao);
    }

    /**
     * Uma entrada por servico e por volume, na ordem. O numero da entrada vai em "nuRequisicao"
     * e volta na resposta, e assim sabemos de quem e cada preco.
     * Calculado de novo na leitura (a partir dos mesmos dados), por isso nao guardamos estado.
     */
    private function entradasDePreco(array $dados): array
    {
        $entradas = [];
        foreach (array_keys($this->servicos()) as $codigo) {
            foreach ($dados['volumes'] as $volume) {
                $entradas[] = ['servico' => (string) $codigo, 'volume' => $volume];
            }
        }

        return $entradas;
    }

    private function cabecalhosComToken(): array
    {
        return ['Authorization' => 'Bearer '.$this->token()];
    }

    private function chaveDoToken(): string
    {
        return 'correios_token:'.$this->credencial->id;
    }

    private function esquecerToken(): void
    {
        Cache::forget($this->chaveDoToken());
    }

    /** Token valido (do cache, ou um novo). */
    private function token(): string
    {
        $guardado = Cache::get($this->chaveDoToken());
        if (is_string($guardado)) {
            return $guardado;
        }

        $c = $this->credencial->credenciais;

        $resposta = $this->http->enviar($this->sellerId, 'correios', 'token', [
            'metodo' => 'POST',
            'url' => config('transportadoras.correios.url').'/token/v1/autentica/cartaopostagem',
            'basic' => [(string) ($c['usuario'] ?? ''), (string) ($c['senha'] ?? '')],
            'corpo' => ['numero' => (string) ($c['cartao_postagem'] ?? '')],
        ]);

        $problema = TransportadoraIndisponivel::motivoDaResposta($resposta);
        $json = $resposta?->json();

        if ($problema !== null) {
            throw new TransportadoraIndisponivel($problema);
        }
        if (! $resposta->successful() || ! is_array($json) || empty($json['token'])) {
            // Os Correios respondem 4xx com mensagem quando usuario/senha/cartao nao conferem.
            throw new TransportadoraIndisponivel($resposta->clientError() ? 'credencial_invalida' : 'resposta_invalida');
        }

        // O PDF mostra "expiraEm" sem fuso (ex.: 2022-03-19T10:26:51). Assumimos horario de Brasilia.
        $segundos = isset($json['expiraEm'])
            ? (int) now()->diffInSeconds(Carbon::parse($json['expiraEm'], 'America/Sao_Paulo'), false) - 300
            : 3600;

        Cache::put($this->chaveDoToken(), $json['token'], max(60, (int) $segundos));

        return $json['token'];
    }
}
