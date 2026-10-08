<?php

declare(strict_types=1);

use App\Models\ChaveApi;
use Illuminate\Support\Facades\DB;

// Autenticacao do backend do cliente: chave de API em "Authorization: Bearer fs_<prefixo>.<segredo>".

it('autentica o cliente com uma chave de API valida', function () {
    [$cliente, $chave] = $this->criarCliente();

    $this->getJson('/api/v1/_teste/eu', $this->auth($chave))
        ->assertOk()
        ->assertJson(['cliente_id' => $cliente->id]);
});

it('guarda so o hash do segredo, nunca o texto', function () {
    [$cliente, $chave] = $this->criarCliente();
    [$prefixo, $segredo] = explode('.', $chave, 2);

    $linha = DB::table('chaves_api')->where('cliente_id', $cliente->id)->first();

    expect($chave)->toMatch('/^fs_[a-f0-9]{12}\.[A-Za-z0-9_-]{43}$/')
        ->and($linha->prefixo)->toBe($prefixo)
        ->and($linha->hash_segredo)->toBe(hash('sha256', $segredo))
        ->and(json_encode($linha))->not->toContain($segredo)
        ->and(json_encode(ChaveApi::where('prefixo', $prefixo)->first()->toArray()))->not->toContain('hash_segredo');
});

it('atualiza o ultimo_uso_em quando a chave e usada', function () {
    [$cliente, $chave] = $this->criarCliente();
    expect($cliente->chaves()->first()->ultimo_uso_em)->toBeNull();

    $this->getJson('/api/v1/_teste/eu', $this->auth($chave))->assertOk();

    expect($cliente->chaves()->first()->ultimo_uso_em)->not->toBeNull();
});

it('responde 401 igual em todos os casos de falha (nao vaza o motivo)', function () {
    config(['frete.limites.auth_falhas_ip' => 1000]); // aqui queremos ver todos os 401, sem bloqueio
    [$cliente, $chave] = $this->criarCliente();
    [$prefixo, $segredo] = explode('.', $chave, 2);

    $respostas = [
        'sem Authorization' => $this->getJson('/api/v1/_teste/eu'),
        'lixo' => $this->getJson('/api/v1/_teste/eu', ['Authorization' => 'Bearer qualquer-coisa']),
        'Basic' => $this->getJson('/api/v1/_teste/eu', ['Authorization' => 'Basic '.base64_encode($cliente->id.':'.$segredo)]),
        'prefixo inexistente' => $this->getJson('/api/v1/_teste/eu', $this->auth('fs_000000000000.'.$segredo)),
        'sem a marca fs_' => $this->getJson('/api/v1/_teste/eu', $this->auth('xx_'.substr($prefixo, 3).'.'.$segredo)),
        'sem ponto' => $this->getJson('/api/v1/_teste/eu', $this->auth($prefixo.$segredo)),
        'sem segredo' => $this->getJson('/api/v1/_teste/eu', $this->auth($prefixo.'.')),
        'segredo errado' => $this->getJson('/api/v1/_teste/eu', $this->auth($prefixo.'.segredo-errado')),
        'chave enorme' => $this->getJson('/api/v1/_teste/eu', $this->auth($prefixo.'.'.str_repeat('a', 500))),
    ];

    $cliente->chaves()->update(['revogada_em' => now()]);
    $respostas['chave revogada'] = $this->getJson('/api/v1/_teste/eu', $this->auth($chave));

    $cliente->chaves()->update(['revogada_em' => null]);
    $cliente->update(['ativo' => false]);
    $respostas['cliente desabilitado'] = $this->getJson('/api/v1/_teste/eu', $this->auth($chave));

    $corpos = [];
    foreach ($respostas as $nome => $resposta) {
        $resposta->assertStatus(401)->assertJsonPath('erro.codigo', 'nao_autenticado');
        $corpo = $resposta->json();
        unset($corpo['request_id']); // esse muda a cada requisicao
        $corpos[$nome] = $corpo;
    }

    // Todos os corpos sao iguais: quem chama nao descobre o motivo.
    expect(array_unique(array_map('json_encode', $corpos)))->toHaveCount(1);
});

it('o 401 manda o header WWW-Authenticate: Bearer', function () {
    $this->getJson('/api/v1/_teste/eu')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Bearer realm="frete-hub"');
});

it('cliente desabilitado nao entra; ao habilitar de novo entra', function () {
    [$cliente, $chave] = $this->criarCliente();

    $cliente->update(['ativo' => false]);
    $this->getJson('/api/v1/_teste/eu', $this->auth($chave))->assertStatus(401);

    $cliente->update(['ativo' => true]);
    $this->getJson('/api/v1/_teste/eu', $this->auth($chave))->assertOk();
});

it('chave revogada nao entra mais', function () {
    [$cliente, $chave] = $this->criarCliente();

    $cliente->chaves()->update(['revogada_em' => now()]);

    $this->getJson('/api/v1/_teste/eu', $this->auth($chave))->assertStatus(401);
});

it('o segredo de uma chave nao serve com o prefixo de outra', function () {
    [, $chaveA] = $this->criarCliente('A');
    [, $chaveB] = $this->criarCliente('B');
    [$prefixoA] = explode('.', $chaveA, 2);
    [, $segredoB] = explode('.', $chaveB, 2);

    $this->getJson('/api/v1/_teste/eu', $this->auth($prefixoA.'.'.$segredoB))->assertStatus(401);
});

it('a chave de um cliente nao enxerga os dados de outro', function () {
    [$a, $chaveA] = $this->criarCliente('A');
    [, $chaveB] = $this->criarCliente('B');
    $cotacao = $this->criarCotacaoComOpcoes($a);

    $this->getJson("/api/v1/cotacoes/{$cotacao->id}", $this->auth($chaveA))->assertOk();
    $this->getJson("/api/v1/cotacoes/{$cotacao->id}", $this->auth($chaveB))->assertStatus(404);
});

it('bloqueia o IP com 429 e Retry-After depois de muitas falhas', function () {
    config(['frete.limites.auth_falhas_ip' => 3]);
    $ruim = 'fs_000000000000.errada';

    foreach (range(1, 3) as $i) {
        $this->getJson('/api/v1/_teste/eu', $this->auth($ruim))->assertStatus(401);
    }

    $resposta = $this->getJson('/api/v1/_teste/eu', $this->auth($ruim));

    $resposta->assertStatus(429)->assertJsonPath('erro.codigo', 'muitas_requisicoes');
    expect((int) $resposta->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('depois do bloqueio nem a chave certa entra, ate a janela passar', function () {
    config(['frete.limites.auth_falhas_ip' => 2]);
    [, $chave] = $this->criarCliente();

    foreach (range(1, 2) as $i) {
        $this->getJson('/api/v1/_teste/eu', $this->auth('fs_000000000000.errada'))->assertStatus(401);
    }

    $this->getJson('/api/v1/_teste/eu', $this->auth($chave))->assertStatus(429);
});
