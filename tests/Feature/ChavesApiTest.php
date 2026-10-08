<?php

declare(strict_types=1);

use App\Enums\PapelUsuario;
use App\Services\ChaveApiService;

// Chaves de API do cliente: o dono cria, lista e revoga pelo portal.

/** Cliente com um dono ja logado no portal. Devolve [cliente, dono]. */
function donoLogado($teste): array
{
    [$cliente] = $teste->criarCliente();
    $dono = $teste->criarUsuario($cliente, PapelUsuario::AdmTenant);
    $teste->actingAs($dono, 'portal');

    return [$cliente, $dono];
}

it('o servico cria chaves diferentes e cada uma funciona sozinha', function () {
    [$cliente] = $this->criarCliente();
    $servico = app(ChaveApiService::class);

    [$chave1, $texto1] = $servico->criar($cliente, 'producao');
    [, $texto2] = $servico->criar($cliente, 'homologacao');

    expect($texto1)->not->toBe($texto2)->and($cliente->chaves()->count())->toBe(3); // 3 = a do helper + 2
    $this->getJson('/api/v1/conta', $this->auth($texto1))->assertOk();
    $this->getJson('/api/v1/conta', $this->auth($texto2))->assertOk();

    $servico->revogar($chave1);
    $this->getJson('/api/v1/conta', $this->auth($texto1))->assertStatus(401);
    $this->getJson('/api/v1/conta', $this->auth($texto2))->assertOk();
});

it('o dono cria varias chaves e o texto completo aparece so na criacao', function () {
    [$cliente] = donoLogado($this);

    $r = $this->postJson('/api/v1/portal/chaves', ['nome' => 'producao'])
        ->assertCreated()
        ->assertJsonPath('data.nome', 'producao')
        ->assertJsonPath('data.revogada_em', null);
    $texto = $r->json('data.chave');

    expect($texto)->toStartWith($r->json('data.prefixo').'.');
    $this->postJson('/api/v1/portal/chaves', ['nome' => 'homologacao'])->assertCreated();

    // a chave nova funciona na API
    $this->getJson('/api/v1/conta', $this->auth($texto))->assertOk()->assertJsonPath('data.id', $cliente->id);

    // a listagem nao traz o texto, so o prefixo
    $lista = $this->getJson('/api/v1/portal/chaves')->assertOk();
    [, $segredo] = explode('.', $texto, 2);
    expect($lista->json('data'))->toHaveCount(3)
        ->and($lista->getContent())->not->toContain($segredo)->not->toContain('hash_segredo')->not->toContain('"chave"')
        ->and(collect($lista->json('data'))->pluck('prefixo')->all())->toContain($r->json('data.prefixo'));
});

it('revogar uma chave nao afeta a outra', function () {
    [$cliente] = donoLogado($this);
    $a = $this->postJson('/api/v1/portal/chaves', ['nome' => 'Chave A'])->assertCreated();
    $b = $this->postJson('/api/v1/portal/chaves', ['nome' => 'Chave B'])->assertCreated();

    $r = $this->deleteJson('/api/v1/portal/chaves/'.$a->json('data.id'))->assertOk();
    expect($r->json('data.revogada_em'))->not->toBeNull();

    $this->getJson('/api/v1/conta', $this->auth($a->json('data.chave')))->assertStatus(401);
    $this->getJson('/api/v1/conta', $this->auth($b->json('data.chave')))->assertOk();
    expect($cliente->chaves()->ativas()->count())->toBe(2); // a do helper e a B
});

it('revogar de novo a mesma chave nao da erro', function () {
    donoLogado($this);
    $id = $this->postJson('/api/v1/portal/chaves', ['nome' => 'Chave A'])->json('data.id');

    $this->deleteJson("/api/v1/portal/chaves/{$id}")->assertOk();
    $this->deleteJson("/api/v1/portal/chaves/{$id}")->assertOk();
});

it('operador nao lista, cria nem revoga chaves (403)', function () {
    [$cliente] = $this->criarCliente();
    $this->actingAs($this->criarUsuario($cliente, PapelUsuario::OperadorTenant), 'portal');
    $chave = $cliente->chaves()->firstOrFail();

    $this->getJson('/api/v1/portal/chaves')->assertStatus(403)->assertJsonPath('erro.codigo', 'proibido');
    $this->postJson('/api/v1/portal/chaves', ['nome' => 'nova'])->assertStatus(403)->assertJsonPath('erro.codigo', 'proibido');
    $this->deleteJson("/api/v1/portal/chaves/{$chave->id}")->assertStatus(403)->assertJsonPath('erro.codigo', 'proibido');

    expect($cliente->chaves()->count())->toBe(1)->and($chave->fresh()->revogada_em)->toBeNull();
});

it('sem login as rotas de chaves dao 401', function () {
    $this->getJson('/api/v1/portal/chaves')->assertStatus(401);
    $this->postJson('/api/v1/portal/chaves', ['nome' => 'x'])->assertStatus(401);
});

it('a lista mostra so as chaves do proprio cliente', function () {
    [$cliente] = donoLogado($this);
    $this->criarCliente('Outro');

    $this->getJson('/api/v1/portal/chaves')->assertOk()->assertJsonCount(1, 'data');
});

it('nao revoga chave de outro cliente (404)', function () {
    donoLogado($this);
    [$outro, $chaveOutro] = $this->criarCliente('Outro');
    $idOutro = $outro->chaves()->firstOrFail()->id;

    $this->deleteJson("/api/v1/portal/chaves/{$idOutro}")->assertStatus(404);

    $this->getJson('/api/v1/conta', $this->auth($chaveOutro))->assertOk();
});

it('o nome da chave e obrigatorio e nao aceita campos desconhecidos', function () {
    [$cliente] = donoLogado($this);

    $this->postJson('/api/v1/portal/chaves', [])->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['nome']]]);
    $this->postJson('/api/v1/portal/chaves', ['nome' => 'a'])->assertStatus(422);
    $this->postJson('/api/v1/portal/chaves', ['nome' => 'producao', 'cliente_id' => 'x'])->assertStatus(422);

    expect($cliente->chaves()->count())->toBe(1);
});
