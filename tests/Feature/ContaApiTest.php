<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

// A conta do cliente (GET /conta). O cadastro das transportadoras (PUT /transportadoras) esta em TransportadorasApiTest.

it('mostra a conta do cliente (sem nenhum segredo)', function () {
    [$cliente, $credenciais] = $this->criarCliente('Loja do Joao');

    $r = $this->getJson('/api/v1/conta', $this->auth($credenciais))
        ->assertOk()
        ->assertJsonPath('data.id', $cliente->id)
        ->assertJsonPath('data.nome', 'Loja do Joao');

    [, $segredo] = explode('.', $credenciais, 2);
    expect($r->getContent())->not->toContain($segredo)->not->toContain('hash')->not->toContain('secret');
});

it('a conta nao tem CEP e nao pode ser alterada pelo cliente', function () {
    [, $credenciais] = $this->criarCliente();

    expect($this->getJson('/api/v1/conta', $this->auth($credenciais))->json('data'))->not->toHaveKey('cep_origem');
    $this->patchJson('/api/v1/conta', ['nome' => 'Outro'], $this->auth($credenciais))->assertStatus(405);
});

it('a conta exige autenticacao', function () {
    $this->getJson('/api/v1/conta')->assertStatus(401);
    $this->getJson('/api/v1/transportadoras')->assertStatus(401);
});

it('nao existem mais as rotas de sellers', function () {
    [, $credenciais] = $this->criarCliente();

    $this->postJson('/api/v1/sellers', ['nome' => 'Loja'], $this->auth($credenciais))->assertStatus(404);
    $this->getJson('/api/v1/sellers', $this->auth($credenciais))->assertStatus(404);
});

it('lista as transportadoras do cliente sem mostrar nenhum segredo', function () {
    [$cliente, $credenciais] = $this->criarCliente();
    $this->configurarJadlog($cliente, token: 'token-secreto-da-jadlog');
    $this->configurarCorreios($cliente);

    $r = $this->getJson('/api/v1/transportadoras', $this->auth($credenciais))->assertOk();

    expect(collect($r->json('data'))->pluck('transportadora')->all())->toBe(['correios', 'jadlog'])
        ->and($r->json('data.1.credenciais_cadastradas'))->toBeTrue()
        ->and($r->json('data.1.configuracao.tomador.nome'))->toBe('Bartofil Comercio Ltda')
        ->and($r->getContent())->not->toContain('token-secreto-da-jadlog')->not->toContain('senha-secreta-dos-correios')->not->toContain('0012345678');
});

it('um cliente nao enxerga as transportadoras, cotacoes nem a conta de outro', function () {
    [$a, $credenciaisA] = $this->criarCliente('Cliente A');
    [$b, $credenciaisB] = $this->criarCliente('Cliente B');
    $this->configurarJadlog($a);
    $cotacaoA = $this->criarCotacaoComOpcoes($a);

    // GET /transportadoras so lista as dele
    $this->getJson('/api/v1/transportadoras', $this->auth($credenciaisB))->assertOk()->assertJsonPath('data', []);
    expect($this->getJson('/api/v1/transportadoras', $this->auth($credenciaisA))->json('data'))->toHaveCount(1);

    // cotacao do A: 404 para o B
    $this->getJson("/api/v1/cotacoes/{$cotacaoA->id}", $this->auth($credenciaisA))->assertOk();
    $this->getJson("/api/v1/cotacoes/{$cotacaoA->id}", $this->auth($credenciaisB))->assertStatus(404);

    // cada um ve a propria conta
    $this->getJson('/api/v1/conta', $this->auth($credenciaisB))->assertJsonPath('data.id', $b->id);
});

// ---------------------------------------------------------------- fluxo completo so por API

it('fluxo completo: cliente novo, transportadora, cotacao e remessa (so por API)', function () {
    Storage::fake('etiquetas');
    config(['frete.etiquetas_disco' => 'etiquetas']);
    [$cliente, $credenciais] = $this->criarCliente('Loja Nova');

    // 1. cadastra a Jadlog pela API (credenciais e contrato do proprio cliente)
    $this->putJson('/api/v1/transportadoras/jadlog', [
        'credenciais' => ['token' => 'token-jadlog'],
        'configuracao' => [
            'tomador' => $this->tomadorDoContrato(),
            'cod_cliente' => '000123',
            'servicos' => [['codigo' => '3', 'nome' => 'Package']],
        ],
    ], $this->auth($credenciais))->assertCreated();

    // 2. cota (o CEP de origem vai no corpo, informado por quem chama)
    $cotacao = $this->postJson('/api/v1/cotacoes', $this->dadosDeCotacao(), $this->auth($credenciais))->assertCreated();
    expect($cotacao->json('data.opcoes'))->not->toBeEmpty()->and($cotacao->json('data.falhas'))->toBe([])
        ->and($cotacao->json('data.cep_origem'))->toBe('01310100');

    // 3. cria a remessa a partir de uma opcao da cotacao
    $opcao = $cotacao->json('data.opcoes.0');
    $cotacaoModelo = $cliente->cotacoes()->findOrFail($cotacao->json('data.id'));
    $remessa = $this->dadosDeRemessa($cotacaoModelo, $opcao['transportadora'], ['servico_codigo' => $opcao['servico_codigo']]);

    $id = $this->postJson('/api/v1/remessas', $remessa, $this->comChave($credenciais))
        ->assertCreated()->assertJsonPath('data.status', 'criada')->json('data.id');

    // 4. consulta
    $this->getJson("/api/v1/remessas/{$id}", $this->auth($credenciais))->assertOk()->assertJsonPath('data.id', $id);
});
