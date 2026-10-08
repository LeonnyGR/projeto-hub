<?php

declare(strict_types=1);

use App\Models\CredencialTransportadora;
use App\Transportadoras\ClienteHttp;
use App\Transportadoras\Correios;
use App\Transportadoras\TransportadoraIndisponivel;
use Illuminate\Support\Facades\Cache;

function correios(string $usuario = 'usuario'): Correios
{
    $credencial = new CredencialTransportadora([
        'transportadora' => 'correios',
        'credenciais' => ['usuario' => $usuario, 'senha' => 'senha', 'cartao_postagem' => '0012345678'],
        'configuracao' => ['servicos' => [['codigo' => '03220', 'nome' => 'SEDEX'], ['codigo' => '04669', 'nome' => 'PAC']]],
    ]);
    $credencial->id = 'credencial-de-teste';

    return new Correios($credencial, app(ClienteHttp::class));
}

function dadosCorreios(int $volumes = 1): array
{
    return [
        'cep_origem' => '70902000', 'cep_destino' => '71930000', 'valor_declarado_centavos' => 10000,
        'volumes' => array_fill(0, $volumes, ['peso_gramas' => 300, 'altura_cm' => 20, 'largura_cm' => 20, 'comprimento_cm' => 20]),
    ];
}

it('pega o token com Basic Auth e guarda no cache', function () {
    $this->criarCliente(); // garante banco para o registro de chamadas

    $pedidos = correios()->pedidosDeCotacao(dadosCorreios());

    expect($pedidos[0]['headers']['Authorization'])->toBe('Bearer eyJhbGciOiJSUzUxMiJ9.token-do-simulador')
        ->and(Cache::get('correios_token:credencial-de-teste'))->toBe('eyJhbGciOiJSUzUxMiJ9.token-do-simulador');

    // Segunda cotacao reaproveita o token (nao chama a API de token de novo).
    $antes = App\Models\ChamadaTransportadora::where('operacao', 'token')->count();
    correios()->pedidosDeCotacao(dadosCorreios());
    expect(App\Models\ChamadaTransportadora::where('operacao', 'token')->count())->toBe($antes);
});

it('monta pedidos de prazo (1 por servico) e de preco (1 por servico e por volume)', function () {
    $pedidos = collect(correios()->pedidosDeCotacao(dadosCorreios(volumes: 3)));

    $prazo = $pedidos->firstWhere('chave', 'prazo-0');
    $preco = $pedidos->where('chave', 'like', 'preco-%');

    expect($prazo['url'])->toBe('https://apihom.correios.com.br/prazo/v1/nacional')
        ->and($prazo['corpo']['parametrosPrazo'])->toHaveCount(2)
        ->and($prazo['corpo']['parametrosPrazo'][0])->toMatchArray(['coProduto' => '03220', 'cepOrigem' => '70902000', 'cepDestino' => '71930000']);

    // 2 servicos x 3 volumes = 6 itens => 2 chamadas (limite de 5 por chamada, como no PDF)
    expect($pedidos->filter(fn ($p) => str_starts_with($p['chave'], 'preco-')))->toHaveCount(2);
    $itens = $pedidos->filter(fn ($p) => str_starts_with($p['chave'], 'preco-'))->flatMap(fn ($p) => $p['corpo']['parametrosProduto']);
    expect($itens)->toHaveCount(6)
        ->and($itens->first())->toMatchArray(['coProduto' => '03220', 'psObjeto' => '300', 'tpObjeto' => '2', 'comprimento' => '20', 'vlDeclarado' => '100.00']);
});

it('soma o preco de todos os volumes e usa o prazo do servico', function () {
    $dados = dadosCorreios(volumes: 2);
    // entradas: 0=SEDEX vol1, 1=SEDEX vol2, 2=PAC vol1, 3=PAC vol2
    $preco = fn (string $produto, int $n, string $final) => ['coProduto' => $produto, 'nuRequisicao' => (string) $n, 'pcFinal' => $final];

    $opcoes = correios()->lerCotacao($dados, [
        'prazo-0' => respostaJson([
            ['coProduto' => '03220', 'nuRequisicao' => '0', 'prazoEntrega' => 1],
            ['coProduto' => '04669', 'nuRequisicao' => '1', 'prazoEntrega' => 5],
        ]),
        'preco-0' => respostaJson([
            $preco('03220', 0, '19,92'), $preco('03220', 1, '1.000,08'),
            $preco('04669', 2, '10,00'), $preco('04669', 3, '10,50'),
        ]),
    ]);

    expect($opcoes)->toBe([
        ['servico_codigo' => '03220', 'servico_nome' => 'SEDEX', 'valor_centavos' => 102000, 'prazo_dias' => 1],
        ['servico_codigo' => '04669', 'servico_nome' => 'PAC', 'valor_centavos' => 2050, 'prazo_dias' => 5],
    ]);
});

it('nao oferece o servico se faltar preco de algum volume ou o prazo', function () {
    $dados = dadosCorreios(volumes: 2);

    $opcoes = correios()->lerCotacao($dados, [
        'prazo-0' => respostaJson([['coProduto' => '03220', 'prazoEntrega' => 1]]), // PAC sem prazo
        'preco-0' => respostaJson([
            ['coProduto' => '03220', 'nuRequisicao' => '0', 'pcFinal' => '10,00'],
            // SEDEX volume 2 sem preco
            ['coProduto' => '04669', 'nuRequisicao' => '2', 'pcFinal' => '10,00'],
            ['coProduto' => '04669', 'nuRequisicao' => '3', 'pcFinal' => '10,00'],
        ]),
    ]);

    expect($opcoes)->toBe([]);
});

it('classifica falhas dos Correios', function (?object $resposta, string $motivo) {
    expect(fn () => correios()->lerCotacao(dadosCorreios(), ['prazo-0' => $resposta, 'preco-0' => $resposta]))
        ->toThrow(fn (TransportadoraIndisponivel $e) => expect($e->motivo)->toBe($motivo));
})->with([
    'sem resposta' => [null, 'sem_resposta'],
    'token recusado' => [fn () => respostaJson(['msgs' => ['x']], 401), 'credencial_invalida'],
    'erro 500' => [fn () => respostaJson('<html/>', 500), 'erro_na_transportadora'],
    'corpo quebrado' => [fn () => respostaJson('{"a":', 200), 'resposta_invalida'],
]);

it('erro de validacao dos Correios (409 com msgs) vira falha sem_cobertura', function () {
    $erro = json_decode(file_get_contents(base_path('app/Transportadoras/Mock/fixtures/correios/erro_validacao.json')), true);

    expect(fn () => correios()->lerCotacao(dadosCorreios(), ['prazo-0' => respostaJson($erro, 409), 'preco-0' => respostaJson($erro, 409)]))
        ->toThrow(fn (TransportadoraIndisponivel $e) => expect($e->motivo)->toBe('sem_cobertura'));
});

it('token recusado ao buscar o token vira credencial_invalida', function () {
    $this->criarCliente();

    expect(fn () => correios('invalido')->pedidosDeCotacao(dadosCorreios()))
        ->toThrow(fn (TransportadoraIndisponivel $e) => expect($e->motivo)->toBe('credencial_invalida'));
});
