<?php

declare(strict_types=1);

use App\Models\Seller;
use Illuminate\Support\Facades\DB;

// Cadastro de sellers por API. As credenciais das transportadoras NAO sao cadastradas por API (so por comando:
// ver ComandoTransportadoraTest); a API so permite consulta.

// ---------------------------------------------------------------- sellers

it('cadastra um seller (e nao gera chave nenhuma)', function () {
    [$cliente, $chave] = $this->criarCliente();

    $r = $this->postJson('/api/v1/sellers', ['nome' => 'Loja do Joao', 'codigo_externo' => 'b2c-123'], $this->auth($chave))
        ->assertCreated()
        ->assertJsonPath('data.nome', 'Loja do Joao')
        ->assertJsonPath('data.codigo_externo', 'b2c-123')
        ->assertJsonPath('data.ativo', true);

    expect($r->json('data.id'))->toMatch('/^[0-9a-f-]{36}$/')
        ->and($r->getContent())->not->toContain('fk_')
        ->and(Seller::find($r->json('data.id'))->cliente_api_id)->toBe($cliente->id);
});

it('codigo_externo repetido da 409 com o id do seller que ja existe', function () {
    [, $chave] = $this->criarCliente();
    $primeiro = $this->postJson('/api/v1/sellers', ['nome' => 'Loja A', 'codigo_externo' => 'b2c-1'], $this->auth($chave))->assertCreated()->json('data.id');

    $this->postJson('/api/v1/sellers', ['nome' => 'Loja A', 'codigo_externo' => 'b2c-1'], $this->auth($chave))
        ->assertStatus(409)
        ->assertJsonPath('erro.codigo', 'seller_ja_existe')
        ->assertJsonPath('erro.detalhes.seller_id', $primeiro);

    expect(Seller::where('codigo_externo', 'b2c-1')->count())->toBe(1);
});

it('sellers sem codigo_externo podem ser varios', function () {
    [, $chave] = $this->criarCliente();

    $this->postJson('/api/v1/sellers', ['nome' => 'Loja 1'], $this->auth($chave))->assertCreated();
    $this->postJson('/api/v1/sellers', ['nome' => 'Loja 2'], $this->auth($chave))->assertCreated();
});

it('valida o cadastro do seller', function (array $dados, string $campo) {
    [, $chave] = $this->criarCliente();

    $this->postJson('/api/v1/sellers', $dados, $this->auth($chave))
        ->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => [$campo]]]);
})->with([
    'sem nome' => [[], 'nome'],
    'nome curto' => [['nome' => 'A'], 'nome'],
    'nome longo' => [['nome' => str_repeat('a', 121)], 'nome'],
    'codigo com caracteres invalidos' => [['nome' => 'Loja', 'codigo_externo' => 'tem espaco'], 'codigo_externo'],
    'campo desconhecido' => [['nome' => 'Loja', 'ativo' => false], 'ativo'],
]);

it('lista e consulta sellers', function () {
    [, $chave] = $this->criarCliente();
    $this->postJson('/api/v1/sellers', ['nome' => 'Zeta'], $this->auth($chave))->assertCreated();
    $alfa = $this->postJson('/api/v1/sellers', ['nome' => 'Alfa'], $this->auth($chave))->json('data.id');

    $r = $this->getJson('/api/v1/sellers', $this->auth($chave))->assertOk();
    expect(collect($r->json('data'))->pluck('nome')->all())->toBe(['Alfa', 'Zeta']); // em ordem alfabetica

    $this->getJson("/api/v1/sellers/{$alfa}", $this->auth($chave))->assertOk()->assertJsonPath('data.nome', 'Alfa');
});

it('atualiza nome e desabilita o seller', function () {
    [$seller, $chave] = $this->criarSeller('Loja Velha');

    $this->patchJson($this->urlDoSeller($seller), ['nome' => 'Loja Nova'], $this->auth($chave))->assertOk()->assertJsonPath('data.nome', 'Loja Nova');
    $this->patchJson($this->urlDoSeller($seller), ['ativo' => false], $this->auth($chave))->assertOk()->assertJsonPath('data.ativo', false);
    $this->patchJson($this->urlDoSeller($seller), ['codigo_externo' => 'x'], $this->auth($chave))->assertStatus(422);
});

it('seller desabilitado nao cota nem cria remessa, mas pode ser consultado e reabilitado', function () {
    [$seller, $chave] = $this->criarSeller();
    $this->configurarJadlog($seller);
    $cotacao = $this->criarCotacaoComOpcoes($seller);
    $seller->update(['ativo' => false]);

    $this->postJson($this->urlDoSeller($seller, '/cotacoes'), $this->dadosDeCotacao(), $this->auth($chave))
        ->assertStatus(409)->assertJsonPath('erro.codigo', 'seller_desabilitado');
    $this->postJson($this->urlDoSeller($seller, '/remessas'), $this->dadosDeRemessa($cotacao), $this->comChave($chave))
        ->assertStatus(409)->assertJsonPath('erro.codigo', 'seller_desabilitado');

    $this->getJson($this->urlDoSeller($seller), $this->auth($chave))->assertOk();
    $this->patchJson($this->urlDoSeller($seller), ['ativo' => true], $this->auth($chave))->assertOk();
    $this->postJson($this->urlDoSeller($seller, '/cotacoes'), $this->dadosDeCotacao(), $this->auth($chave))->assertCreated();
});

it('o cadastro exige a chave do cliente da API', function () {
    $this->postJson('/api/v1/sellers', ['nome' => 'Loja'])->assertStatus(401);
    $this->getJson('/api/v1/sellers')->assertStatus(401);
    $this->getJson('/api/v1/transportadoras')->assertStatus(401);
});

it('um cliente da API nao enxerga os sellers de outro (404), e codigo_externo e por cliente', function () {
    [$a, $chaveA] = $this->criarCliente('B2C A');
    [$b, $chaveB] = $this->criarCliente('B2C B');

    $seller = $this->postJson('/api/v1/sellers', ['nome' => 'Loja do A', 'codigo_externo' => 'x-1'], $this->auth($chaveA))->assertCreated()->json('data.id');

    $this->getJson("/api/v1/sellers/{$seller}", $this->auth($chaveB))->assertStatus(404);
    $this->postJson("/api/v1/sellers/{$seller}/cotacoes", $this->dadosDeCotacao(), $this->auth($chaveB))->assertStatus(404);
    expect($this->getJson('/api/v1/sellers', $this->auth($chaveB))->json('data'))->toBe([]);

    // o mesmo codigo_externo pode existir em outro cliente
    $this->postJson('/api/v1/sellers', ['nome' => 'Loja do B', 'codigo_externo' => 'x-1'], $this->auth($chaveB))->assertCreated();
});

// ---------------------------------------------------------------- contrato do B2C com a transportadora



// ---------------------------------------------------------------- transportadoras: so consulta pela API

it('lista as transportadoras do B2C sem mostrar nenhum segredo', function () {
    [$seller, $chave] = $this->criarSeller();
    $this->configurarJadlog($seller, token: 'token-secreto-da-jadlog');
    $this->configurarCorreios($seller);

    $r = $this->getJson('/api/v1/transportadoras', $this->auth($chave))->assertOk();

    expect(collect($r->json('data'))->pluck('transportadora')->all())->toBe(['correios', 'jadlog'])
        ->and($r->json('data.1.credenciais_cadastradas'))->toBeTrue()
        ->and($r->json('data.1.configuracao.tomador.nome'))->toBe('Bartofil Comercio Ltda')
        ->and($r->getContent())->not->toContain('token-secreto-da-jadlog')->not->toContain('senha');
});

it('a API NAO grava nem altera credenciais de transportadora', function (string $metodo, string $caminho) {
    [, $chave] = $this->criarCliente();

    $this->json($metodo, $caminho, ['credenciais' => ['token' => 'x']], $this->auth($chave))->assertStatus(in_array($metodo, ['PUT', 'PATCH']) ? 404 : 405);
    expect(\App\Models\CredencialTransportadora::count())->toBe(0);
})->with([
    'PUT em /transportadoras/jadlog' => ['PUT', '/api/v1/transportadoras/jadlog'],
    'PATCH em /transportadoras/jadlog' => ['PATCH', '/api/v1/transportadoras/jadlog'],
    'POST em /transportadoras' => ['POST', '/api/v1/transportadoras'],
]);

it('um cliente da API nao enxerga as transportadoras de outro', function () {
    [$a] = $this->criarCliente('B2C A');
    [, $chaveB] = $this->criarCliente('B2C B');
    $sellerA = $a->sellers()->create(['nome' => 'Loja do A', 'ativo' => true]);
    $this->configurarJadlog($sellerA);

    $this->getJson('/api/v1/transportadoras', $this->auth($chaveB))->assertOk()->assertJsonPath('data', []);
});

// ---------------------------------------------------------------- fluxo completo so por API

it('fluxo completo: seller novo por API, cotacao e remessa (credenciais do B2C ja cadastradas)', function () {
    \Illuminate\Support\Facades\Storage::fake('etiquetas');
    config(['frete.etiquetas_disco' => 'etiquetas']);
    [$cliente, $chave] = $this->criarCliente('B2C');

    // 1. as credenciais sao cadastradas UMA vez pela TI (comando transportadora:configurar; aqui, pelo atalho de teste)
    $sellerDeApoio = $cliente->sellers()->create(['nome' => 'apoio', 'ativo' => true]);
    $this->configurarJadlog($sellerDeApoio);
    $this->configurarCorreios($sellerDeApoio);

    // 2. um seller novo ja pode cotar, sem nenhum cadastro de transportadora proprio
    $seller = $this->postJson('/api/v1/sellers', ['nome' => 'Loja Nova', 'codigo_externo' => 'b2c-777'], $this->auth($chave))->assertCreated()->json('data.id');

    $cotacao = $this->postJson("/api/v1/sellers/{$seller}/cotacoes", $this->dadosDeCotacao(), $this->auth($chave))->assertCreated();
    expect($cotacao->json('data.opcoes'))->not->toBeEmpty()->and($cotacao->json('data.falhas'))->toBe([]);

    $opcao = $cotacao->json('data.opcoes.0');
    $cotacaoModelo = Seller::find($seller)->cotacoes()->findOrFail($cotacao->json('data.id'));
    $dados = $this->dadosDeRemessa($cotacaoModelo, $opcao['transportadora'], ['servico_codigo' => $opcao['servico_codigo']]);

    $this->postJson("/api/v1/sellers/{$seller}/remessas", $dados, $this->comChave($chave))
        ->assertCreated()->assertJsonPath('data.status', 'criada');
});
