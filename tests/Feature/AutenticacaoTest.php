<?php

declare(strict_types=1);

use App\Exceptions\ErroDeNegocio;
use App\Models\ChaveApi;
use App\Services\ApiKeyService;
use Illuminate\Support\Facades\Artisan;

// A chave de API e do cliente da API (o B2C). Os sellers nao tem chave.

it('autentica o cliente com chave valida', function () {
    [$cliente, $chave] = $this->criarCliente();

    $this->getJson('/api/v1/_teste/eu', $this->auth($chave))
        ->assertOk()
        ->assertJson(['cliente_id' => $cliente->id]);
});

it('guarda so o hash do segredo, nunca a chave', function () {
    [$cliente, $chave] = $this->criarCliente();
    [$prefixo, $segredo] = ApiKeyService::separar($chave);

    $linha = ChaveApi::where('cliente_api_id', $cliente->id)->firstOrFail();

    expect($linha->prefixo)->toBe($prefixo)
        ->and($linha->hash_segredo)->toBe(hash('sha256', $segredo))
        ->and(json_encode($linha->getAttributes()))->not->toContain($segredo);
});

it('responde 401 igual em todos os casos de falha', function () {
    [$cliente, $chave] = $this->criarCliente();
    [$prefixo] = ApiKeyService::separar($chave);

    $respostas = [
        'sem header' => $this->getJson('/api/v1/_teste/eu'),
        'lixo' => $this->getJson('/api/v1/_teste/eu', $this->auth('lixo')),
        'prefixo inexistente' => $this->getJson('/api/v1/_teste/eu', $this->auth('fk_000000000000.abc')),
        'segredo errado' => $this->getJson('/api/v1/_teste/eu', $this->auth($prefixo.'.errado')),
    ];

    $cliente->update(['ativo' => false]);
    $respostas['cliente desabilitado'] = $this->getJson('/api/v1/_teste/eu', $this->auth($chave));

    $corpos = [];
    foreach ($respostas as $nome => $resposta) {
        $resposta->assertStatus(401)->assertJsonPath('erro.codigo', 'nao_autenticado');
        $corpo = $resposta->json();
        unset($corpo['request_id']); // esse muda a cada requisicao
        $corpos[$nome] = $corpo;
    }

    // Todos os corpos sao iguais: quem chama nao descobre se a chave ou o cliente existe.
    expect(array_unique(array_map('json_encode', $corpos)))->toHaveCount(1);
});

it('nao autentica chave expirada nem revogada', function () {
    [$cliente, $chave] = $this->criarCliente();

    ChaveApi::where('cliente_api_id', $cliente->id)->update(['expira_em' => now()->subMinute()]);
    $this->getJson('/api/v1/_teste/eu', $this->auth($chave))->assertStatus(401);

    ChaveApi::where('cliente_api_id', $cliente->id)->update(['expira_em' => null, 'revogada_em' => now()]);
    $this->getJson('/api/v1/_teste/eu', $this->auth($chave))->assertStatus(401);
});

it('na troca de chave as duas funcionam; depois da tolerancia so a nova', function () {
    [$cliente, $antiga] = $this->criarCliente();

    $nova = app(ApiKeyService::class)->trocarChave($cliente, false, 24);

    $this->getJson('/api/v1/_teste/eu', $this->auth($antiga))->assertOk();
    $this->getJson('/api/v1/_teste/eu', $this->auth($nova))->assertOk();
    expect(ChaveApi::where('cliente_api_id', $cliente->id)->ativas()->count())->toBe(2);

    $this->travel(25)->hours();

    $this->getJson('/api/v1/_teste/eu', $this->auth($antiga))->assertStatus(401);
    $this->getJson('/api/v1/_teste/eu', $this->auth($nova))->assertOk();
});

it('trocar a chave revogando a antiga invalida a antiga na hora', function () {
    [$cliente, $antiga] = $this->criarCliente();

    $nova = app(ApiKeyService::class)->trocarChave($cliente, true);

    $this->getJson('/api/v1/_teste/eu', $this->auth($antiga))->assertStatus(401);
    $this->getJson('/api/v1/_teste/eu', $this->auth($nova))->assertOk();
});

it('nao deixa passar de duas chaves ativas', function () {
    [$cliente] = $this->criarCliente();
    $servico = app(ApiKeyService::class);
    $servico->trocarChave($cliente);

    expect(fn () => $servico->trocarChave($cliente))->toThrow(ErroDeNegocio::class);
});

it('bloqueia o IP com 429 e Retry-After depois de muitas falhas', function () {
    config(['frete.limites.auth_falhas_ip' => 3]);

    foreach (range(1, 3) as $i) {
        $this->getJson('/api/v1/_teste/eu', $this->auth('fk_000000000000.x'))->assertStatus(401);
    }

    $resposta = $this->getJson('/api/v1/_teste/eu', $this->auth('fk_000000000000.x'));

    $resposta->assertStatus(429)->assertJsonPath('erro.codigo', 'muitas_requisicoes');
    expect((int) $resposta->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('comandos cliente:create, rotate-key, disable e list', function () {
    Artisan::call('cliente:create', ['nome' => 'B2C']);
    $saida = Artisan::output();

    preg_match('/(fk_[a-f0-9]{12}\.[A-Za-z0-9_-]+)/', $saida, $chave);
    preg_match('/Cliente criado: ([0-9a-f-]{36})/', $saida, $id);
    expect($chave)->not->toBeEmpty()->and($id)->not->toBeEmpty();

    $this->getJson('/api/v1/_teste/eu', $this->auth($chave[1]))->assertOk();

    Artisan::call('cliente:rotate-key', ['cliente' => $id[1]]);
    expect(Artisan::output())->toContain('Nova chave');

    Artisan::call('cliente:list');
    expect(Artisan::output())->toContain($id[1])->toContain('B2C');

    Artisan::call('cliente:disable', ['cliente' => $id[1]]);
    $this->getJson('/api/v1/_teste/eu', $this->auth($chave[1]))->assertStatus(401);

    Artisan::call('cliente:disable', ['cliente' => $id[1], '--enable' => true]);
    $this->getJson('/api/v1/_teste/eu', $this->auth($chave[1]))->assertOk();
});

it('o seller nao tem chave: criar seller nao gera chave nenhuma', function () {
    $antes = ChaveApi::count();

    $this->criarSeller();
    $this->criarSeller('Outro');

    expect(ChaveApi::count())->toBe($antes + 1); // so a do cliente, criada uma vez
});
