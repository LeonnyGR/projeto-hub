<?php

declare(strict_types=1);

use App\Enums\PapelUsuario;
use App\Enums\StatusRemessa;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('etiquetas');
    config(['frete.etiquetas_disco' => 'etiquetas']);
});

/** Cliente com as duas transportadoras e um usuario do papel pedido logado no portal. Devolve [cliente, chaveApi, usuario]. */
function portalComPapel($teste, PapelUsuario $papel): array
{
    [$cliente, $chave] = $teste->criarClienteComAsDuas();
    $usuario = $teste->criarUsuario($cliente, $papel);
    $teste->actingAs($usuario, 'portal');

    return [$cliente, $chave, $usuario];
}

function contratoJadlog($teste): array
{
    return [
        'credenciais' => ['token' => 'token-jadlog'],
        'configuracao' => [
            'tomador' => $teste->tomadorDoContrato(),
            'cod_cliente' => '000123',
            'servicos' => [['codigo' => '3', 'nome' => 'Package']],
        ],
    ];
}

// ---------------------------------------------------------------- dia a dia: dono e operador

it('dono e operador cotam, criam remessa e pegam a etiqueta pelo portal', function (PapelUsuario $papel) {
    [$cliente] = portalComPapel($this, $papel);

    $cotacaoId = $this->postJson('/api/v1/portal/cotacoes', $this->dadosDeCotacao())->assertCreated()->json('data.id');
    $this->getJson("/api/v1/portal/cotacoes/{$cotacaoId}")->assertOk();

    $cotacao = $cliente->cotacoes()->findOrFail($cotacaoId);
    $id = $this->postJson('/api/v1/portal/remessas', $this->dadosDeRemessa($cotacao), $this->comChave('x'))
        ->assertCreated()->assertJsonPath('data.status', 'criada')->json('data.id');

    $this->getJson("/api/v1/portal/remessas/{$id}")->assertOk()->assertJsonPath('data.id', $id);
    $this->get("/api/v1/portal/remessas/{$id}/etiqueta")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->getJson('/api/v1/portal/conta')->assertOk()->assertJsonPath('data.id', $cliente->id);
    $this->getJson('/api/v1/portal/transportadoras')->assertOk()->assertJsonCount(2, 'data');
})->with([
    'dono' => [PapelUsuario::AdmTenant],
    'operador' => [PapelUsuario::OperadorTenant],
]);

it('o portal exige Idempotency-Key ao criar remessa, como a chave de API', function () {
    [$cliente] = portalComPapel($this, PapelUsuario::OperadorTenant);
    $cotacao = $this->criarCotacaoComOpcoes($cliente);

    $this->postJson('/api/v1/portal/remessas', $this->dadosDeRemessa($cotacao))->assertStatus(422);
});

it('operador despacha e cancela remessa e cria devolucao pelo portal', function () {
    [$cliente] = portalComPapel($this, PapelUsuario::OperadorTenant);
    $cotacao = $this->criarCotacaoComOpcoes($cliente);
    $id = $this->postJson('/api/v1/portal/remessas', $this->dadosDeRemessa($cotacao), $this->comChave('a'))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/portal/remessas/{$id}/despachar")->assertOk()->assertJsonPath('data.status', 'despachada');
    $this->postJson("/api/v1/portal/remessas/{$id}/cancelar")->assertOk()->assertJsonPath('data.status', 'cancelada');

    $cliente->remessas()->whereKey($id)->update(['status' => StatusRemessa::Entregue->value]);
    $this->postJson('/api/v1/portal/devolucoes', ['remessa_id' => $id, 'motivo' => 'Defeito'], $this->comChave('b'))
        ->assertCreated()->assertJsonPath('data.tipo', 'devolucao');
});

// ---------------------------------------------------------------- transportadoras: so o dono

it('operador recebe 403 em PUT e PATCH das transportadoras', function () {
    [$cliente] = portalComPapel($this, PapelUsuario::OperadorTenant);

    $this->putJson('/api/v1/portal/transportadoras/jadlog', contratoJadlog($this))->assertStatus(403)->assertJsonPath('erro.codigo', 'proibido');
    $this->patchJson('/api/v1/portal/transportadoras/jadlog', ['ativa' => false])->assertStatus(403)->assertJsonPath('erro.codigo', 'proibido');

    expect($cliente->credenciais()->where('transportadora', 'jadlog')->first()->ativa)->toBeTrue();
});

it('o dono cadastra (201) e liga/desliga (200) a transportadora pelo portal', function () {
    [$cliente] = $this->criarCliente();
    $this->actingAs($this->criarUsuario($cliente, PapelUsuario::AdmTenant), 'portal');

    $r = $this->putJson('/api/v1/portal/transportadoras/jadlog', contratoJadlog($this))->assertCreated();
    expect($r->getContent())->not->toContain('token-jadlog');

    $this->patchJson('/api/v1/portal/transportadoras/jadlog', ['ativa' => false])->assertOk()->assertJsonPath('data.ativa', false);
    $this->putJson('/api/v1/portal/transportadoras/jadlog', contratoJadlog($this))->assertOk();
});

// ---------------------------------------------------------------- isolamento entre clientes

it('um cliente nao enxerga cotacao, remessa nem transportadoras de outro pelo portal', function () {
    [$a, , $usuarioA] = portalComPapel($this, PapelUsuario::AdmTenant);
    [$b] = $this->criarClienteComAsDuas('Outro');
    $cotacaoB = $this->criarCotacaoComOpcoes($b);
    $remessaB = $this->criarRemessa($b, $cotacaoB);

    $this->getJson("/api/v1/portal/cotacoes/{$cotacaoB->id}")->assertStatus(404);
    $this->getJson("/api/v1/portal/remessas/{$remessaB->id}")->assertStatus(404);
    $this->get("/api/v1/portal/remessas/{$remessaB->id}/etiqueta")->assertStatus(404);
    $this->postJson("/api/v1/portal/remessas/{$remessaB->id}/cancelar")->assertStatus(404);
    $this->postJson('/api/v1/portal/remessas', $this->dadosDeRemessa($cotacaoB), $this->comChave('z'))->assertStatus(404);

    // GET /transportadoras so as do proprio cliente
    $b->credenciais()->where('transportadora', 'correios')->delete();
    $r = $this->getJson('/api/v1/portal/transportadoras')->assertOk();
    expect(collect($r->json('data'))->pluck('transportadora')->all())->toBe(['correios', 'jadlog']);
    $this->getJson('/api/v1/portal/conta')->assertJsonPath('data.id', $a->id);
});

// ---------------------------------------------------------------- listagens (chave de API e portal)

/** Cria remessas direto no banco. Devolve os ids na ordem de criacao. */
function remessasParaListar($teste, $cliente, array $statuses, string $tipo = 'envio'): array
{
    $cotacao = $teste->criarCotacao($cliente);
    $ids = [];

    foreach ($statuses as $i => $status) {
        $remessa = $teste->criarRemessa($cliente, $cotacao);
        $remessa->forceFill(['tipo' => $tipo, 'status' => $status, 'created_at' => now()->subMinutes(10 - $i)])->save();
        $ids[] = $remessa->id;
    }

    return $ids;
}

/** Faz a mesma chamada pelas duas portas: chave de API e portal. */
function pelasDuasPortas($teste, $cliente, $chave, string $caminho): array
{
    return [
        'chave de API' => fn () => $teste->getJson("/api/v1/{$caminho}", $teste->auth($chave)),
        'portal' => function () use ($teste, $cliente, $caminho) {
            esquecerAutenticacao($teste);
            $teste->actingAs($teste->criarUsuario($cliente, PapelUsuario::OperadorTenant), 'portal');

            return $teste->getJson("/api/v1/portal/{$caminho}");
        },
    ];
}

it('GET /remessas lista so os envios do cliente, mais novos primeiro', function () {
    [$cliente, $chave] = $this->criarCliente();
    [$outro] = $this->criarCliente('Outro');
    $ids = remessasParaListar($this, $cliente, ['criada', 'entregue', 'criada']);
    $idDevolucao = remessasParaListar($this, $cliente, ['criada'], 'devolucao')[0];
    $idOutro = remessasParaListar($this, $outro, ['criada'])[0];

    foreach (pelasDuasPortas($this, $cliente, $chave, 'remessas') as $porta => $chamar) {
        $r = $chamar()->assertOk();
        $recebidos = collect($r->json('data'))->pluck('id')->all();

        expect($recebidos)->toBe(array_reverse($ids), $porta)
            ->and($recebidos)->not->toContain($idDevolucao)->not->toContain($idOutro);
    }
});

it('GET /remessas filtra por status e status invalido da 422', function () {
    [$cliente, $chave] = $this->criarCliente();
    $ids = remessasParaListar($this, $cliente, ['criada', 'entregue', 'criada']);

    foreach (pelasDuasPortas($this, $cliente, $chave, 'remessas?status=entregue') as $porta => $chamar) {
        expect(collect($chamar()->assertOk()->json('data'))->pluck('id')->all())->toBe([$ids[1]], $porta);
    }

    foreach (pelasDuasPortas($this, $cliente, $chave, 'remessas?status=inventado') as $chamar) {
        $chamar()->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['status']]]);
    }
});

it('GET /devolucoes lista so as devolucoes do cliente e filtra por status', function () {
    [$cliente, $chave] = $this->criarCliente();
    [$outro] = $this->criarCliente('Outro');
    $ids = remessasParaListar($this, $cliente, ['criada', 'cancelada'], 'devolucao');
    $idEnvio = remessasParaListar($this, $cliente, ['criada'])[0];
    $idOutro = remessasParaListar($this, $outro, ['criada'], 'devolucao')[0];

    foreach (pelasDuasPortas($this, $cliente, $chave, 'devolucoes') as $porta => $chamar) {
        $recebidos = collect($chamar()->assertOk()->json('data'))->pluck('id')->all();
        expect($recebidos)->toBe(array_reverse($ids), $porta)->and($recebidos)->not->toContain($idEnvio)->not->toContain($idOutro);
    }

    foreach (pelasDuasPortas($this, $cliente, $chave, 'devolucoes?status=cancelada') as $chamar) {
        expect(collect($chamar()->assertOk()->json('data'))->pluck('id')->all())->toBe([$ids[1]]);
    }

    foreach (pelasDuasPortas($this, $cliente, $chave, 'devolucoes?status=xx') as $chamar) {
        $chamar()->assertStatus(422);
    }
});

it('a listagem e paginada (50 por pagina)', function () {
    [$cliente, $chave] = $this->criarCliente();
    remessasParaListar($this, $cliente, array_fill(0, 51, 'criada'));

    $pagina1 = $this->getJson('/api/v1/remessas', $this->auth($chave))->assertOk();
    expect($pagina1->json('data'))->toHaveCount(50)->and($pagina1->json('meta.total'))->toBe(51);

    $this->getJson('/api/v1/remessas?page=2', $this->auth($chave))->assertOk()->assertJsonCount(1, 'data');
});

it('as listagens exigem autenticacao', function () {
    $this->getJson('/api/v1/remessas')->assertStatus(401);
    $this->getJson('/api/v1/devolucoes')->assertStatus(401);
    $this->getJson('/api/v1/portal/remessas')->assertStatus(401);
    $this->getJson('/api/v1/portal/devolucoes')->assertStatus(401);
});
