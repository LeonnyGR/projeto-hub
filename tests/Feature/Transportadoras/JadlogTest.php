<?php

declare(strict_types=1);

use App\Models\CredencialTransportadora;
use App\Transportadoras\ClienteHttp;
use App\Transportadoras\Jadlog;
use App\Transportadoras\TransportadoraIndisponivel;
use Illuminate\Http\Client\Response;

function jadlog(array $servicos = [['codigo' => '3', 'nome' => 'Package']]): Jadlog
{
    $credencial = new CredencialTransportadora([
        'transportadora' => 'jadlog',
        'credenciais' => ['token' => 'meu-token'],
        'configuracao' => ['tomador' => ['documento' => '12345678000195'], 'servicos' => $servicos],
    ]);

    return new Jadlog($credencial, app(ClienteHttp::class));
}

function fixtureJadlog(string $nome): array
{
    return json_decode(file_get_contents(base_path("app/Transportadoras/Mock/fixtures/jadlog/{$nome}.json")), true);
}

it('monta o pedido de simulacao como no PDF', function () {
    $pedidos = jadlog()->pedidosDeCotacao([
        'cep_origem' => '01310100', 'cep_destino' => '20040020', 'valor_declarado_centavos' => 5500,
        'volumes' => [['peso_gramas' => 300, 'altura_cm' => 1, 'largura_cm' => 1, 'comprimento_cm' => 1]],
    ]);

    expect($pedidos)->toHaveCount(1)
        ->and($pedidos[0]['metodo'])->toBe('POST')
        ->and($pedidos[0]['url'])->toBe('https://www.jadlog.com.br/embarcador/api/frete/valor')
        ->and($pedidos[0]['headers'])->toBe(['Authorization' => 'meu-token']);

    $item = $pedidos[0]['corpo']['frete'][0];
    expect($item)->toMatchArray([
        'cepori' => '01310100', 'cepdes' => '20040020', 'frap' => 'N', 'peso' => 0.3, 'cnpj' => '12345678000195',
        'modalidade' => 3, 'tpentrega' => 'D', 'tpseguro' => 'N', 'vldeclarado' => 55.0, 'vlcoleta' => 0,
    ]);
});

it('usa o maior entre peso real e peso cubado, somando os volumes', function () {
    // volume 1: real 0,5 kg; cubado 60x50x40 / 6000 = 20 kg  => 20
    // volume 2: real 2 kg;   cubado 10x10x10 / 6000 = 0,167  => 2
    $pedidos = jadlog()->pedidosDeCotacao([
        'cep_origem' => '01310100', 'cep_destino' => '20040020', 'valor_declarado_centavos' => 0,
        'volumes' => [
            ['peso_gramas' => 500, 'altura_cm' => 60, 'largura_cm' => 50, 'comprimento_cm' => 40],
            ['peso_gramas' => 2000, 'altura_cm' => 10, 'largura_cm' => 10, 'comprimento_cm' => 10],
        ],
    ]);

    expect($pedidos[0]['corpo']['frete'][0]['peso'])->toBe(22.0);
});

it('separa as modalidades em chamadas de no maximo 3 (limite do PDF)', function () {
    $servicos = array_map(fn ($c) => ['codigo' => (string) $c, 'nome' => "S{$c}"], [3, 0, 5, 4]);

    $pedidos = jadlog($servicos)->pedidosDeCotacao([
        'cep_origem' => '01310100', 'cep_destino' => '20040020', 'valor_declarado_centavos' => 0,
        'volumes' => [['peso_gramas' => 500, 'altura_cm' => 10, 'largura_cm' => 10, 'comprimento_cm' => 10]],
    ]);

    expect($pedidos)->toHaveCount(2)
        ->and($pedidos[0]['corpo']['frete'])->toHaveCount(3)
        ->and($pedidos[1]['corpo']['frete'])->toHaveCount(1)
        ->and($pedidos[0]['chave'])->not->toBe($pedidos[1]['chave']);
});

it('le a resposta de sucesso do PDF e converte para centavos', function () {
    $opcoes = jadlog()->lerCotacao([], ['frete-0' => respostaJson(fixtureJadlog('frete_valor_sucesso'))]);

    expect($opcoes)->toBe([[
        'servico_codigo' => '3', 'servico_nome' => 'Package', 'valor_centavos' => 750, 'prazo_dias' => 5,
    ]]);
});

it('erro de negocio da Jadlog (error ou erro) vira falha sem_cobertura', function () {
    expect(fn () => jadlog()->lerCotacao([], ['frete-0' => respostaJson(fixtureJadlog('frete_valor_erro'))]))
        ->toThrow(fn (TransportadoraIndisponivel $e) => expect($e->motivo)->toBe('sem_cobertura'));

    // O PDF usa "error" no exemplo e "erro" na tabela de campos; e o HTTP pode ser 400.
    $comErro = fixtureJadlog('frete_valor_erro');
    $comErro['erro'] = $comErro['error'];
    unset($comErro['error']);

    expect(fn () => jadlog()->lerCotacao([], ['frete-0' => respostaJson($comErro, 400)]))
        ->toThrow(fn (TransportadoraIndisponivel $e) => expect($e->motivo)->toBe('sem_cobertura'));
});

it('se uma chamada tem erro de negocio e outra traz opcoes, usa as opcoes', function () {
    $opcoes = jadlog()->lerCotacao([], [
        'frete-0' => respostaJson(fixtureJadlog('frete_valor_erro')),
        'frete-1' => respostaJson(fixtureJadlog('frete_valor_sucesso')),
    ]);

    expect($opcoes)->toHaveCount(1);
});

it('classifica as falhas da transportadora', function (?Response $resposta, string $motivo) {
    expect(fn () => jadlog()->lerCotacao([], ['frete-0' => $resposta]))
        ->toThrow(fn (TransportadoraIndisponivel $e) => expect($e->motivo)->toBe($motivo));
})->with([
    'sem resposta' => [null, 'sem_resposta'],
    'token recusado' => [fn () => respostaJson(['descricao' => 'TOKEN INVALIDO', 'id' => 1], 401), 'credencial_invalida'],
    'erro 500' => [fn () => respostaJson('<html>erro</html>', 500), 'erro_na_transportadora'],
    'json quebrado' => [fn () => respostaJson('{"frete": [ {"vltotal": 7.5, '), 'resposta_invalida'],
]);

it('com uma chamada boa e outra ruim, usa a boa', function () {
    $opcoes = jadlog()->lerCotacao([], [
        'frete-0' => respostaJson(fixtureJadlog('frete_valor_sucesso')),
        'frete-1' => null,
    ]);

    expect($opcoes)->toHaveCount(1);
});
