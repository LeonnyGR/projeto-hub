<?php

declare(strict_types=1);

namespace App\Transportadoras\Mock;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Simula as transportadoras quando TRANSPORTADORAS_MODO=mock. O ViaCEP so e simulado com VIACEP_MODO=mock (testes). O codigo das classes Jadlog e Correios
 * roda de verdade; so a resposta HTTP e falsa, lida de arquivos em Mock/fixtures (copias dos exemplos dos PDFs).
 *
 * O cenario e escolhido pelo CEP de destino (tabela completa em Mock/README.md).
 * Instalado em AppServiceProvider (e recusado quando APP_ENV=production).
 */
class SimuladorHttp
{
    /** CEP de destino => cenario. Qualquer outro CEP = sucesso. */
    private const CENARIOS = [
        '22222222' => 'sem_cobertura',
        '33333333' => 'timeout',
        '44444444' => 'erro_500',
        '55555555' => 'resposta_malformada',
        '66666666' => 'credencial_invalida',
    ];

    /** Usado como token da Jadlog / usuario dos Correios para simular credencial recusada. */
    public const CREDENCIAL_INVALIDA = 'invalido';

    public static function instalar(): void
    {
        Http::fake(fn (Request $requisicao) => self::responder($requisicao));
    }

    public static function responder(Request $requisicao): mixed
    {
        $caminho = (string) parse_url($requisicao->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($caminho, '/frete/valor') => self::jadlogFrete($requisicao),
            str_ends_with($caminho, '/pedido/incluir') => self::jadlogCriarRemessa($requisicao),
            str_ends_with($caminho, '/pedido/cancelar') => self::jadlogCancelarRemessa($requisicao),
            str_ends_with($caminho, '/prepostagens/rotulo/assincrono/pdf') => self::correiosPedirEtiqueta($requisicao),
            str_contains($caminho, '/prepostagens/rotulo/download/assincrono/') => self::correiosBaixarEtiqueta($requisicao),
            str_contains($caminho, '/prepostagem/v1/prepostagens') => self::correiosPrePostagem($requisicao, $caminho),
            str_contains($caminho, '/pudos') => self::jadlogPontos($requisicao),
            str_ends_with($caminho, '/token/v1/autentica/cartaopostagem') => self::correiosToken($requisicao),
            str_ends_with($caminho, '/prazo/v1/nacional') => self::correiosPrazo($requisicao),
            str_ends_with($caminho, '/preco/v1/nacional') => self::correiosPreco($requisicao),
            // ViaCEP: por padrao NAO e simulado (null = a chamada segue para a internet de verdade).
            str_starts_with($caminho, '/ws/') && str_ends_with($caminho, '/json/') => config('transportadoras.viacep.modo') === 'mock'
                ? self::viaCep($requisicao, basename(dirname($caminho)))
                : null,
            default => Http::response(['erro' => 'URL nao simulada: '.$caminho], 404),
        };
    }

    // ---------------------------------------------------------------- Jadlog

    private static function jadlogFrete(Request $requisicao): mixed
    {
        if (($requisicao->header('Authorization')[0] ?? '') === self::CREDENCIAL_INVALIDA) {
            return Http::response(self::fixture('jadlog/erro_token'), 401);
        }

        $itens = $requisicao->data()['frete'] ?? [];
        $cenario = self::cenario($itens[0]['cepdes'] ?? '');

        if ($falha = self::falhaComum($requisicao, $cenario)) {
            return $falha;
        }

        if ($cenario === 'sem_cobertura') {
            $erro = self::fixture('jadlog/frete_valor_erro');
            $erro['frete'] = $itens;

            return Http::response($erro, 200);
        }

        // Sucesso: um item por modalidade pedida, com preco e prazo previsiveis.
        $modelo = self::fixture('jadlog/frete_valor_sucesso')['frete'][0];
        $resposta = [];
        foreach ($itens as $item) {
            $modalidade = (int) ($item['modalidade'] ?? 0);
            $resposta[] = array_merge($modelo, $item, [
                'prazo' => 3 + ($modalidade % 5),
                'vltotal' => round(5 + $modalidade * 2 + ((float) ($item['peso'] ?? 0)) * 3, 2),
            ]);
        }

        return Http::response(['frete' => $resposta], 200);
    }

    private static function jadlogPontos(Request $requisicao): mixed
    {
        if (($requisicao->header('Authorization')[0] ?? '') === self::CREDENCIAL_INVALIDA) {
            return Http::response(self::fixture('jadlog/erro_token'), 401);
        }

        parse_str((string) parse_url($requisicao->url(), PHP_URL_QUERY), $query);
        $cep = preg_replace('/\D/', '', (string) ($query['cep'] ?? ''));
        $cenario = self::cenario($cep);

        if ($falha = self::falhaComum($requisicao, $cenario)) {
            return $falha;
        }

        // Sem cobertura: a Jadlog devolve lista vazia.
        return Http::response($cenario === 'sem_cobertura' ? ['pudos' => []] : self::fixture('jadlog/pudos'), 200);
    }

    private static function jadlogCriarRemessa(Request $requisicao): mixed
    {
        if (($requisicao->header('Authorization')[0] ?? '') === self::CREDENCIAL_INVALIDA) {
            return Http::response(self::fixture('jadlog/erro_token'), 401);
        }

        $dados = $requisicao->data();
        $cenario = self::cenario($dados['des']['cep'] ?? '');

        if ($falha = self::falhaComum($requisicao, $cenario)) {
            return $falha;
        }
        if ($cenario === 'sem_cobertura') {
            return Http::response(self::fixture('jadlog/pedido_incluir_erro'), 200);
        }

        // shipmentId de 14 digitos, previsivel a partir do conteudo do pedido.
        // Termina sempre em "01": os finais 77 e 99 sao reservados para simular cancelamento recusado/ja cancelado.
        $shipmentId = str_pad((string) abs(crc32(json_encode($dados))), 12, '0', STR_PAD_LEFT).'01';

        $resposta = self::fixture('jadlog/pedido_incluir_sucesso');
        $resposta['codigo'] = substr($shipmentId, -9);
        $resposta['shipmentId'] = $shipmentId;
        $resposta['etiqueta']['arquivo'] = base64_encode((string) file_get_contents(__DIR__.'/fixtures/etiqueta_exemplo.pdf'));
        $resposta['etiqueta']['volume'] = [];
        foreach (array_keys($dados['volume'] ?? []) as $i) {
            $resposta['etiqueta']['volume'][] = ['codbarra' => $shipmentId.'$00'.($i + 1), 'seqVolume' => $i + 1, 'lastMile' => '30', 'posicao' => '00', 'prioridade' => 3, 'rota' => 'SP-SJK-0952-SAO03', 'rua' => 'A', 'unidadeDestino' => 'CO CARAGUATATUBA 01'];
        }

        return Http::response($resposta, 200);
    }

    private static function jadlogCancelarRemessa(Request $requisicao): mixed
    {
        if (($requisicao->header('Authorization')[0] ?? '') === self::CREDENCIAL_INVALIDA) {
            return Http::response(self::fixture('jadlog/erro_token'), 401);
        }

        $shipmentId = (string) ($requisicao->data()['shipmentId'] ?? '');

        // Mock: pedidos cujo shipmentId termina em 99 ja estao cancelados; em 77 a transportadora recusa.
        if (str_ends_with($shipmentId, '99')) {
            return Http::response(self::fixture('jadlog/pedido_cancelar_erro'), 200);
        }
        if (str_ends_with($shipmentId, '77')) {
            $erro = self::fixture('jadlog/pedido_cancelar_erro');
            $erro['erro']['detalhe'] = 'Pedido ja foi coletado (texto ilustrativo do simulador).';

            return Http::response($erro, 200);
        }

        return Http::response(array_merge(self::fixture('jadlog/pedido_cancelar_sucesso'), ['shipmentId' => $shipmentId]), 200);
    }

    // ---------------------------------------------------------------- Correios

    private static function correiosPrePostagem(Request $requisicao, string $caminho): mixed
    {
        if ($requisicao->method() === 'DELETE') {
            $id = basename($caminho);

            // Mock: ids que terminam em "NAOEXISTE" nao existem mais; em "RECUSA" a transportadora recusa.
            return match (true) {
                str_ends_with($id, 'NAOEXISTE') => Http::response(self::fixture('correios/prepostagem_nao_encontrada'), 404),
                str_ends_with($id, 'RECUSA') => Http::response(self::fixture('correios/prepostagem_erro'), 409),
                default => Http::response('', 204),
            };
        }

        $dados = $requisicao->data();
        $cenario = self::cenario($dados['destinatario']['endereco']['cep'] ?? '');

        if ($falha = self::falhaComum($requisicao, $cenario)) {
            return $falha;
        }
        if ($cenario === 'sem_cobertura') {
            return Http::response(self::fixture('correios/prepostagem_erro'), 409);
        }

        // id de 24 caracteres (PR + 22) e codigo de objeto no formato AA999999999BR, previsiveis a partir do pedido
        $semente = abs(crc32(json_encode($dados).microtime()));

        return Http::response(array_merge(self::fixture('correios/prepostagem_sucesso'), [
            'id' => 'PR'.substr(strtoupper(md5((string) $semente)), 0, 22),
            'codigoObjeto' => 'AA'.str_pad((string) ($semente % 1_000_000_000), 9, '0', STR_PAD_LEFT).'BR',
        ]), 200);
    }

    private static function correiosPedirEtiqueta(Request $requisicao): mixed
    {
        // Resposta nao documentada no PDF (premissa): um recibo para baixar o PDF depois.
        return Http::response(['idRecibo' => 'RECIBO-SIMULADO'], 200);
    }

    private static function correiosBaixarEtiqueta(Request $requisicao): mixed
    {
        return Http::response([
            'nome' => 'etiquetas.pdf',
            'dados' => base64_encode((string) file_get_contents(__DIR__.'/fixtures/etiqueta_exemplo.pdf')),
        ], 200);
    }

    private static function correiosToken(Request $requisicao): mixed
    {
        $usuario = explode(':', base64_decode(substr($requisicao->header('Authorization')[0] ?? '', 6)), 2)[0];

        if ($usuario === self::CREDENCIAL_INVALIDA) {
            return Http::response(self::fixture('correios/erro_credencial'), 401);
        }

        $token = self::fixture('correios/token');
        $token['emissao'] = now('America/Sao_Paulo')->format('Y-m-d\TH:i:s');
        $token['expiraEm'] = now('America/Sao_Paulo')->addDay()->format('Y-m-d\TH:i:s');

        return Http::response($token, 201);
    }

    private static function correiosPrazo(Request $requisicao): mixed
    {
        $itens = $requisicao->data()['parametrosPrazo'] ?? [];
        $cenario = self::cenario($itens[0]['cepDestino'] ?? '');

        if ($falha = self::falhaComum($requisicao, $cenario)) {
            return $falha;
        }
        if ($cenario === 'sem_cobertura') {
            return Http::response(self::fixture('correios/erro_validacao'), 409);
        }

        $modelo = self::fixture('correios/prazo');
        $resposta = [];
        foreach ($itens as $item) {
            $resposta[] = array_merge($modelo, [
                'coProduto' => $item['coProduto'],
                'nuRequisicao' => $item['nuRequisicao'],
                'prazoEntrega' => 1 + ((int) substr((string) $item['coProduto'], -2) % 6),
            ]);
        }

        return Http::response($resposta, 200);
    }

    private static function correiosPreco(Request $requisicao): mixed
    {
        $itens = $requisicao->data()['parametrosProduto'] ?? [];
        $cenario = self::cenario($itens[0]['cepDestino'] ?? '');

        if ($falha = self::falhaComum($requisicao, $cenario)) {
            return $falha;
        }
        if ($cenario === 'sem_cobertura') {
            return Http::response(self::fixture('correios/erro_validacao'), 409);
        }

        $modelo = self::fixture('correios/preco');
        $resposta = [];
        foreach ($itens as $item) {
            $peso = (int) ($item['psObjeto'] ?? 0);
            $valor = 10 + ((int) substr((string) $item['coProduto'], -2) % 10) + $peso / 1000 * 2;

            $resposta[] = array_merge($modelo, [
                'coProduto' => $item['coProduto'],
                'nuRequisicao' => $item['nuRequisicao'],
                'psCobrado' => (string) $peso,
                // Os Correios devolvem valores como texto no formato brasileiro ("19,92").
                'pcFinal' => number_format($valor, 2, ',', '.'),
            ]);
        }

        return Http::response($resposta, 200);
    }

    private static function viaCep(Request $requisicao, string $cep): mixed
    {
        $cenario = self::cenario($cep);

        // O ViaCEP nao tem credencial: "credencial_invalida" nao se aplica e responde como sucesso.
        if ($cenario !== 'credencial_invalida' && $falha = self::falhaComum($requisicao, $cenario)) {
            return $falha;
        }

        // CEP que nao existe: o ViaCEP responde 200 com {"erro": true}
        if ($cenario === 'sem_cobertura') {
            return Http::response(self::fixture('viacep/cep_nao_encontrado'), 200);
        }

        $endereco = self::fixture('viacep/cep');
        $endereco['cep'] = substr($cep, 0, 5).'-'.substr($cep, 5);

        return Http::response($endereco, 200);
    }

    // ---------------------------------------------------------------- comum

    private static function cenario(string $cep): string
    {
        return self::CENARIOS[preg_replace('/\D/', '', $cep)] ?? 'sucesso';
    }

    /** Cenarios de falha que valem para qualquer endpoint. Devolve null se o cenario nao e uma falha comum. */
    private static function falhaComum(Request $requisicao, string $cenario): mixed
    {
        return match ($cenario) {
            // O fake nao aplica timeout de verdade: simulamos o resultado dele (falha de conexao).
            'timeout' => Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out (simulado)', $requisicao->toPsrRequest())),
            'erro_500' => Http::response('<html><body>Internal Server Error (simulado)</body></html>', 500),
            'resposta_malformada' => Http::response('{"frete": [ {"vltotal": 7.5, ', 200),
            'credencial_invalida' => Http::response(['descricao' => 'TOKEN INVALIDO', 'id' => 1], 401),
            default => null,
        };
    }

    private static function fixture(string $nome): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/'.$nome.'.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
