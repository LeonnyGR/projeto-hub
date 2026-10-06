<?php

declare(strict_types=1);

namespace App\Transportadoras;

use App\Exceptions\ErroDeNegocio;
use App\Models\CredencialTransportadora;
use App\Support\Dinheiro;
use App\Support\Pessoa;
use Illuminate\Validation\Rule;

/**
 * Integracao com a Jadlog (PDF "Integracao JADLOG", v2.3).
 * Autenticacao: header "Authorization: <token>" em toda chamada.
 */
class Jadlog implements Transportadora, PontosDeRetirada
{
    public static function regras(): array
    {
        return [
            'credenciais' => ['required', 'array'],
            'credenciais.token' => ['required', 'string', 'max:2000'],

            'configuracao' => ['required', 'array'],
            // Titular do contrato = quem paga o frete (tomador). Nome, documento, telefone e endereco completos.
            ...Pessoa::regras('configuracao.tomador'),
            'configuracao.cod_cliente' => ['required', 'string', 'max:20'],
            'configuracao.conta' => ['nullable', 'string', 'max:6'],
            'configuracao.contrato' => ['nullable', 'digits_between:1,12'],
            'configuracao.unidade_origem' => ['nullable', 'string', 'max:9'],
            // K = a Jadlog coleta no seller; S = o seller leva o pacote ate a Jadlog
            'configuracao.tipo_coleta' => ['nullable', Rule::in(['K', 'S'])],
            // Modalidades contratadas (codigo numerico, como no PDF)
            'configuracao.servicos' => ['required', 'array', 'min:1', 'max:20'],
            'configuracao.servicos.*.codigo' => ['required', 'string', 'regex:/^\d{1,3}$/'],
            'configuracao.servicos.*.nome' => ['required', 'string', 'max:60'],
        ];
    }

    public static function perguntas(): array
    {
        return [
            ['campo' => 'credenciais.token', 'rotulo' => 'Token da Jadlog', 'secreto' => true],
            ['campo' => 'configuracao.tomador.nome', 'rotulo' => 'Tomador do frete (titular do contrato): nome ou razao social'],
            ['campo' => 'configuracao.tomador.documento', 'rotulo' => 'Tomador: CNPJ'],
            ['campo' => 'configuracao.tomador.telefone', 'rotulo' => 'Tomador: telefone com DDD'],
            ['campo' => 'configuracao.tomador.email', 'rotulo' => 'Tomador: e-mail (Enter para pular)'],
            ['campo' => 'configuracao.tomador.endereco.cep', 'rotulo' => 'Tomador: CEP'],
            ['campo' => 'configuracao.tomador.endereco.logradouro', 'rotulo' => 'Tomador: rua'],
            ['campo' => 'configuracao.tomador.endereco.numero', 'rotulo' => 'Tomador: numero'],
            ['campo' => 'configuracao.tomador.endereco.complemento', 'rotulo' => 'Tomador: complemento (Enter para pular)'],
            ['campo' => 'configuracao.tomador.endereco.bairro', 'rotulo' => 'Tomador: bairro'],
            ['campo' => 'configuracao.tomador.endereco.cidade', 'rotulo' => 'Tomador: cidade'],
            ['campo' => 'configuracao.tomador.endereco.uf', 'rotulo' => 'Tomador: UF'],
            ['campo' => 'configuracao.cod_cliente', 'rotulo' => 'Codigo do cliente na Jadlog'],
            ['campo' => 'configuracao.conta', 'rotulo' => 'Conta corrente Jadlog (Enter se nao for correntista)'],
            ['campo' => 'configuracao.contrato', 'rotulo' => 'Numero do contrato (Enter se nao tiver)'],
            ['campo' => 'configuracao.unidade_origem', 'rotulo' => 'Codigo da unidade (franquia) emissora (Enter se nao souber)'],
            ['campo' => 'configuracao.tipo_coleta', 'rotulo' => 'Tipo de coleta: K = Jadlog coleta no seller, S = seller leva ate a Jadlog', 'padrao' => 'K'],
            ['campo' => 'configuracao.servicos', 'rotulo' => 'Modalidades contratadas, no formato codigo:nome,codigo:nome (ex.: 3:Package,0:Expresso)', 'tipo' => 'servicos'],
        ];
    }

    /** A Jadlog aceita de 1 a 3 modalidades por chamada de simulacao (PDF, simulador de frete). */
    private const MAX_MODALIDADES_POR_CHAMADA = 3;

    /** Tipo de documento fiscal (campo tpDocumento do PDF). */
    private const TIPO_DOCUMENTO = ['declaracao' => 0, 'nf' => 1, 'nfe' => 2, 'cte' => 4, 'dce' => 5];

    public function __construct(
        private readonly CredencialTransportadora $credencial,
        private readonly ClienteHttp $http,
        // Seller em nome de quem estamos chamando (so para o historico de chamadas).
        private readonly ?string $sellerId = null,
    ) {
    }

    public function codigo(): string
    {
        return 'jadlog';
    }

    public function pedidosDeCotacao(array $dados): array
    {
        $pesoKg = $this->pesoEmKg($dados['volumes']);
        $config = $this->credencial->configuracao;

        $itens = [];
        foreach ($this->servicos() as $codigo => $nome) {
            $itens[] = [
                'cepori' => $dados['cep_origem'],
                'cepdes' => $dados['cep_destino'],
                'frap' => 'N',
                'peso' => $pesoKg,
                'cnpj' => $config['tomador']['documento'] ?? null,
                'conta' => ($config['conta'] ?? '') !== '' ? $config['conta'] : null,
                'contrato' => ($config['contrato'] ?? '') !== '' ? $config['contrato'] : null,
                'modalidade' => (int) $codigo,
                'tpentrega' => 'D',
                'tpseguro' => 'N',
                'vldeclarado' => (float) Dinheiro::paraDecimal($dados['valor_declarado_centavos']),
                'vlcoleta' => 0,
            ];
        }

        $pedidos = [];
        foreach (array_chunk($itens, self::MAX_MODALIDADES_POR_CHAMADA) as $numero => $grupo) {
            $pedidos[] = [
                'chave' => "frete-{$numero}",
                'metodo' => 'POST',
                'url' => config('transportadoras.jadlog.url_embarcador').'/frete/valor',
                'headers' => $this->cabecalhos(),
                'corpo' => ['frete' => $grupo],
            ];
        }

        return $pedidos;
    }

    public function lerCotacao(array $dados, array $respostas): array
    {
        $nomes = $this->servicos();
        $opcoes = [];
        $respostaValida = false;
        $semCobertura = false;
        $motivo = 'sem_resposta';

        foreach ($respostas as $resposta) {
            $problema = TransportadoraIndisponivel::motivoDaResposta($resposta);
            if ($problema !== null) {
                $motivo = $problema;

                continue;
            }

            $json = $resposta->json();
            if (! is_array($json)) {
                $motivo = 'resposta_invalida';

                continue;
            }

            // Erro de negocio (ex.: contrato invalido, sem cobertura): a Jadlog respondeu, so nao ha opcao.
            // O PDF usa "error" no exemplo e "erro" na tabela de campos: aceitamos os dois.
            if (isset($json['error']) || isset($json['erro'])) {
                $respostaValida = true;
                $semCobertura = true;

                continue;
            }

            if (! $resposta->successful()) {
                $motivo = 'resposta_invalida';

                continue;
            }

            $respostaValida = true;
            foreach ($json['frete'] ?? [] as $item) {
                if (isset($item['erro']) || isset($item['error']) || ! isset($item['vltotal'], $item['modalidade'])) {
                    continue;
                }

                $codigo = (string) $item['modalidade'];
                $opcoes[] = [
                    'servico_codigo' => $codigo,
                    'servico_nome' => $nomes[$codigo] ?? $codigo,
                    'valor_centavos' => Dinheiro::deReais($item['vltotal']),
                    'prazo_dias' => isset($item['prazo']) ? (int) $item['prazo'] : null,
                ];
            }
        }

        if (! $respostaValida) {
            throw new TransportadoraIndisponivel($motivo);
        }

        // A Jadlog respondeu, mas so com erro de negocio (ex.: CEP fora da area): o seller precisa saber por que nao ha opcao.
        if ($opcoes === [] && $semCobertura) {
            throw new TransportadoraIndisponivel('sem_cobertura');
        }

        return $opcoes;
    }

    /** Tipos de ponto que a Jadlog conhece (PDF, "Consulta Pickup Points"). */
    private const TIPOS_DE_PONTO = ['LOCKER', 'PUBLICO', 'JADLOG'];

    /** Pontos Pickup (lockers, pontos publicos e franquias Jadlog) perto de um CEP. */
    public function pontosDeRetirada(string $cep, ?string $tipos = null): array
    {
        // Fica so com os tipos que a Jadlog conhece; se sobrar nenhum, usa todos.
        $pedidos = array_intersect(array_map('trim', explode(',', strtoupper((string) $tipos))), self::TIPOS_DE_PONTO);
        $tipos = implode(',', $pedidos !== [] ? $pedidos : self::TIPOS_DE_PONTO);

        $resposta = $this->http->enviar($this->sellerId, 'jadlog', 'pontos_retirada', [
            'metodo' => 'GET',
            'url' => config('transportadoras.jadlog.url_pontos').'/pudos/location',
            'headers' => $this->cabecalhos(),
            // O PDF mostra o CEP com hifen (02031-100).
            'query' => ['tipos' => $tipos, 'cep' => substr($cep, 0, 5).'-'.substr($cep, 5)],
        ]);

        $problema = TransportadoraIndisponivel::motivoDaResposta($resposta);
        $json = $resposta?->json();

        if ($problema !== null || ! $resposta->successful() || ! is_array($json)) {
            throw new TransportadoraIndisponivel($problema ?? 'resposta_invalida');
        }

        $pontos = [];
        foreach ($json['pudos'] ?? [] as $pudo) {
            $endereco = $pudo['pudoEnderecoList'][0] ?? [];
            $pontos[] = [
                'id' => $pudo['pudoId'] ?? null,
                'nome' => $pudo['razao'] ?? null,
                'tipo_codigo' => $pudo['pudoTipo'] ?? null,
                'logradouro' => $endereco['endereco'] ?? null,
                'numero' => $endereco['numero'] ?? null,
                'bairro' => $endereco['bairro'] ?? null,
                'cidade' => $endereco['cidade'] ?? null,
                'uf' => $endereco['uf'] ?? null,
                'cep' => $endereco['cep'] ?? null,
                'latitude' => $endereco['latitude'] ?? null,
                'longitude' => $endereco['longitude'] ?? null,
            ];
        }

        return $pontos;
    }

    public function criarRemessa(array $remessa): array
    {
        $config = $this->credencial->configuracao;
        $devolucao = $remessa['tipo'] === 'devolucao';
        $valorDeclarado = (float) Dinheiro::paraDecimal($remessa['valor_declarado_centavos']);

        $volumes = [];
        foreach ($remessa['volumes'] as $i => $volume) {
            $volumes[] = [
                'altura' => $volume['altura_cm'],
                'comprimento' => $volume['comprimento_cm'],
                'largura' => $volume['largura_cm'],
                'peso' => round($volume['peso_gramas'] / 1000, 3),
                'identificador' => substr($remessa['id'].'-'.($i + 1), 0, 40),
            ];
        }

        $corpo = [
            'codCliente' => (string) ($config['cod_cliente'] ?? ''),
            'conteudo' => $remessa['conteudo'],
            'pedido' => $remessa['numero_pedido'] !== null ? [$remessa['numero_pedido']] : null,
            'totPeso' => round(array_sum(array_column($volumes, 'peso')), 3),
            'totValor' => $valorDeclarado,
            'obs' => null,
            'modalidade' => (int) $remessa['servico_codigo'],
            'contaCorrente' => ($config['conta'] ?? '') !== '' ? $config['conta'] : null,
            // Devolucao: o cliente leva o pacote a um ponto (Dropoff), entao a solicitacao e eletronica ("S").
            'tpColeta' => $devolucao ? 'S' : (($config['tipo_coleta'] ?? '') !== '' ? $config['tipo_coleta'] : 'K'),
            'tipoFrete' => 0,
            'cdUnidadeOri' => ($config['unidade_origem'] ?? '') !== '' ? $config['unidade_origem'] : null,
            'cdUnidadeDes' => null,
            'cdPickupOri' => null,
            'cdPickupDes' => $remessa['ponto_retirada_id'],
            'nrContrato' => ($config['contrato'] ?? '') !== '' ? (int) $config['contrato'] : null,
            // 0 = sem PIN, 2 = Dropoff (usado na devolucao)
            'servico' => $devolucao ? 2 : 0,
            'shipmentId' => null,
            'vlColeta' => null,
            'rem' => $this->pessoa($remessa['remetente']),
            // Tomador = quem paga o frete = titular do contrato (o B2C); expedidor = quem despacha o pacote.
            'tomador' => $this->pessoa($config['tomador']),
            'exp' => $this->pessoa($remessa['remetente']),
            'des' => $this->pessoa($remessa['destinatario']),
            'dfe' => [$this->documentoFiscal($remessa, $valorDeclarado)],
            'volume' => $volumes,
        ];

        $resposta = $this->http->enviar($this->sellerId, 'jadlog', 'criar_remessa', [
            'metodo' => 'POST',
            'url' => config('transportadoras.jadlog.url_embarcador').'/pedido/incluir',
            'headers' => $this->cabecalhos(),
            'corpo' => $corpo,
        ]);

        $problema = TransportadoraIndisponivel::motivoDaResposta($resposta);
        if ($problema !== null) {
            throw new TransportadoraIndisponivel($problema);
        }

        $json = $resposta->json();
        if (! is_array($json)) {
            throw new TransportadoraIndisponivel('resposta_invalida');
        }

        // A Jadlog avisa recusa com o grupo "erro" (ex.: "Destino fora da area de cobertura").
        if (isset($json['erro'])) {
            $descricao = mb_strtolower((string) ($json['erro']['descricao'] ?? $json['erro']['descrição'] ?? ''));

            throw new ErroDeNegocio('remessa_recusada', 'A transportadora recusou a remessa.', 422, [
                'motivo' => str_contains($descricao, 'cobertura') ? 'sem_cobertura' : 'recusada',
            ]);
        }

        if (! $resposta->successful() || empty($json['shipmentId'])) {
            throw new TransportadoraIndisponivel('resposta_invalida');
        }

        // Na Jadlog o pedido todo tem um unico codigo; todos os volumes o compartilham.
        $codigo = (string) $json['shipmentId'];

        return [
            'id_externo' => $codigo,
            'codigo_rastreio' => $codigo,
            'volumes' => array_map(fn () => ['id_externo' => $codigo, 'codigo_rastreio' => $codigo], $remessa['volumes']),
            // O PDF da etiqueta (base64) vem aqui, se a etiqueta estiver habilitada no contrato.
            'etiqueta_pdf' => $this->lerPdf($json['etiqueta']['arquivo'] ?? null),
        ];
    }

    public function cancelarRemessa(array $remessa): void
    {
        $resposta = $this->http->enviar($this->sellerId, 'jadlog', 'cancelar_remessa', [
            'metodo' => 'POST',
            'url' => config('transportadoras.jadlog.url_embarcador').'/pedido/cancelar',
            'headers' => $this->cabecalhos(),
            'corpo' => ['shipmentId' => $remessa['id_externo']],
        ]);

        $problema = TransportadoraIndisponivel::motivoDaResposta($resposta);
        if ($problema !== null) {
            throw new TransportadoraIndisponivel($problema);
        }

        $json = $resposta->json();
        if (! is_array($json)) {
            throw new TransportadoraIndisponivel('resposta_invalida');
        }

        if (isset($json['erro'])) {
            // "Pedido ja esta cancelado" nao e problema: o resultado que queremos ja existe.
            if (str_contains(mb_strtolower((string) ($json['erro']['detalhe'] ?? $json['erro']['detalhes'] ?? '')), 'cancelado')) {
                return;
            }

            throw new ErroDeNegocio('cancelamento_recusado', 'A transportadora recusou o cancelamento.', 422);
        }

        if (! $resposta->successful()) {
            throw new TransportadoraIndisponivel('resposta_invalida');
        }
    }

    /** A Jadlog nao tem endpoint de etiqueta: o PDF vem so na criacao (ver criarRemessa). */
    public function etiqueta(array $remessa): ?string
    {
        return null;
    }

    /** Pessoa no formato do PDF (grupos rem, tomador, exp, des). */
    private function pessoa(array $p): array
    {
        $fixo = strlen($p['telefone']) === 10;
        $telefone = substr($p['telefone'], 0, 2).' '.substr($p['telefone'], 2); // "11 999999999"

        return [
            'nome' => $p['nome'],
            'cnpjCpf' => $p['documento'],
            'ie' => null,
            'endereco' => $p['endereco']['logradouro'],
            'numero' => $p['endereco']['numero'],
            'compl' => $p['endereco']['complemento'] ?? null,
            'bairro' => $p['endereco']['bairro'],
            'cidade' => $p['endereco']['cidade'],
            'uf' => $p['endereco']['uf'],
            'cep' => $p['endereco']['cep'],
            'fone' => $fixo ? $telefone : null,
            'cel' => $fixo ? null : $telefone,
            'email' => $p['email'] ?? null,
            'contato' => mb_substr($p['nome'], 0, 50),
        ];
    }

    /**
     * Documento fiscal (grupo "dfe", obrigatorio no PDF). Sem nota informada, enviamos uma
     * declaracao de conteudo manual, como o PDF orienta ("DEC123").
     */
    private function documentoFiscal(array $remessa, float $valor): array
    {
        $doc = $remessa['documento_fiscal'];

        if ($doc === null) {
            return [
                'cfop' => null, 'danfeCte' => null, 'serie' => null, 'tpDocumento' => 0, 'valor' => $valor,
                'nrDoc' => 'DEC'.strtoupper(substr(str_replace('-', '', $remessa['id']), -8)),
            ];
        }

        return [
            'cfop' => $doc['cfop'] ?? null,
            'danfeCte' => $doc['chave'] ?? null,
            'nrDoc' => $doc['numero'] ?? null,
            'serie' => $doc['serie'] ?? null,
            'tpDocumento' => self::TIPO_DOCUMENTO[$doc['tipo']],
            'valor' => $valor,
        ];
    }

    /** Decodifica o PDF (base64). Devolve null se nao for um PDF de verdade. */
    private function lerPdf(mixed $base64): ?string
    {
        if (! is_string($base64) || $base64 === '') {
            return null;
        }

        $pdf = base64_decode($base64, true);

        return $pdf !== false && str_starts_with($pdf, '%PDF') ? $pdf : null;
    }

    /** ['3' => 'Package', '0' => 'Expresso'] a partir da configuracao do seller. */
    private function servicos(): array
    {
        return Transportadoras::servicosDaConfiguracao($this->credencial->configuracao);
    }

    private function cabecalhos(): array
    {
        // Segundo o PDF o header e "Authorization: <token>" (sem "Bearer").
        return ['Authorization' => (string) ($this->credencial->credenciais['token'] ?? '')];
    }

    /**
     * Peso total em kg. Para cada volume vale o MAIOR entre o peso real e o peso cubado
     * (regra do PDF: "sempre informar o maior peso").
     */
    private function pesoEmKg(array $volumes): float
    {
        $divisor = (int) config('transportadoras.divisor_cubagem');
        $total = 0.0;

        foreach ($volumes as $v) {
            $real = $v['peso_gramas'] / 1000;
            $cubado = ($v['altura_cm'] * $v['largura_cm'] * $v['comprimento_cm']) / $divisor;
            $total += max($real, $cubado);
        }

        return round($total, 3);
    }
}
