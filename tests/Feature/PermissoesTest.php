<?php

declare(strict_types=1);

use App\Enums\PapelUsuario;
use App\Models\Aplicacao;
use App\Models\Modulo;
use App\Models\Usuario;
use App\Services\CatalogoDePermissoes;

/** Cliente + dono logado no portal. Devolve [cliente, dono]. */
function donoDoPortal($teste): array
{
    [$cliente] = $teste->criarCliente();
    $dono = $teste->criarUsuario($cliente, PapelUsuario::AdmTenant);
    $teste->actingAs($dono, 'portal');

    return [$cliente, $dono];
}

function operadorDoPortal($teste, array $mapa, $cliente = null): Usuario
{
    $cliente ??= $teste->criarCliente()[0];
    $operador = $teste->liberar($teste->criarUsuario($cliente, PapelUsuario::OperadorTenant), $mapa);
    $teste->actingAs($operador, 'portal');

    return $operador;
}

// ---------------------------------------------------------------- catalogo

it('o catalogo do banco tem os 5 modulos e as 9 aplicacoes do config', function () {
    expect(Modulo::pluck('codigo')->all())->toEqualCanonicalizing(['COT', 'OPE', 'REV', 'TRA', 'ADM'])
        ->and(Aplicacao::count())->toBe(9)
        ->and(Aplicacao::find('COT_01')->caminho)->toBe('cotacao/cotar')
        ->and(Modulo::find('COT')->icone)->toBe('bi bi-calculator');
});

it('sincronizar e idempotente e apaga o que saiu do config', function () {
    $original = config('permissoes.modulos');
    config(['permissoes.modulos' => ['COT' => $original['COT']]]);

    app(CatalogoDePermissoes::class)->sincronizar();
    expect(Modulo::count())->toBe(1)->and(Aplicacao::count())->toBe(2);

    config(['permissoes.modulos' => $original]);
    app(CatalogoDePermissoes::class)->sincronizar();
    app(CatalogoDePermissoes::class)->sincronizar();
    expect(Modulo::count())->toBe(5)->and(Aplicacao::count())->toBe(9);
});

// ---------------------------------------------------------------- menu (/eu)

it('o dono ve todos os modulos e aplicacoes com todas as acoes', function () {
    donoDoPortal($this);

    $r = $this->getJson('/api/v1/portal/eu')->assertOk()->assertJsonPath('data.acesso_total', true);

    expect(collect($r->json('data.menu'))->pluck('codigo')->all())->toBe(['COT', 'OPE', 'REV', 'TRA', 'ADM'])
        ->and($r->json('data.menu.1.icone'))->toBe('bi bi-box-seam')
        ->and($r->json('data.menu.1.aplicacoes.1'))->toBe([
            'codigo' => 'OPE_02', 'nome' => 'Listar Remessas', 'caminho' => 'operacao/remessa/listar', 'acoes' => ['ver', 'etiqueta', 'despachar', 'cancelar'],
        ]);
});

it('o operador so ve o que foi liberado; modulo sem aplicacao liberada some', function () {
    operadorDoPortal($this, ['OPE_02' => ['ver', 'etiqueta'], 'TRA_01' => ['ver']]);

    $r = $this->getJson('/api/v1/portal/eu')->assertOk()->assertJsonPath('data.acesso_total', false);

    expect(collect($r->json('data.menu'))->pluck('codigo')->all())->toBe(['OPE', 'TRA'])
        ->and(collect($r->json('data.menu.0.aplicacoes'))->pluck('codigo')->all())->toBe(['OPE_02'])
        ->and($r->json('data.menu.0.aplicacoes.0.acoes'))->toBe(['ver', 'etiqueta']);
});

it('operador recem-criado, sem nada liberado, tem menu vazio', function () {
    operadorDoPortal($this, []);

    $this->getJson('/api/v1/portal/eu')->assertOk()->assertJsonPath('data.menu', []);
});

// ---------------------------------------------------------------- middleware nas rotas

it('operador sem a acao recebe 403 sem_permissao; com a acao passa', function () {
    [$cliente] = $this->criarCliente();
    $operador = operadorDoPortal($this, ['OPE_02' => ['ver']], $cliente);

    $this->getJson('/api/v1/portal/remessas')->assertOk();
    $this->getJson('/api/v1/portal/devolucoes')->assertStatus(403)->assertJsonPath('erro.codigo', 'sem_permissao')
        ->assertJsonPath('erro.detalhes.aplicacao', 'REV_01');
    $this->postJson('/api/v1/portal/cotacoes', $this->dadosDeCotacao())->assertStatus(403)->assertJsonPath('erro.codigo', 'sem_permissao');

    $this->liberar($operador, ['OPE_02' => ['ver'], 'COT_01' => ['criar']]);
    $this->getJson('/api/v1/portal/cotacoes')->assertStatus(403); // COT_02 ver nao foi liberada
});

it('a acao e checada: ver nao da etiqueta nem cancelar', function () {
    [$cliente] = $this->criarCliente();
    $operador = operadorDoPortal($this, ['OPE_02' => ['ver']], $cliente);
    $remessa = $this->criarRemessa($cliente, $this->criarCotacao($cliente));

    $this->getJson("/api/v1/portal/remessas/{$remessa->id}")->assertOk();
    $this->getJson("/api/v1/portal/remessas/{$remessa->id}/etiqueta")->assertStatus(403);
    $this->postJson("/api/v1/portal/remessas/{$remessa->id}/cancelar")->assertStatus(403);
    $this->postJson("/api/v1/portal/remessas/{$remessa->id}/despachar")->assertStatus(403);
});

it('a chave de API nao e afetada pelas permissoes do portal', function () {
    [, $chave] = $this->criarCliente();

    $this->getJson('/api/v1/remessas', $this->auth($chave))->assertOk();
    $this->getJson('/api/v1/cotacoes', $this->auth($chave))->assertOk();
});

it('GET /cotacoes lista so as cotacoes do cliente', function () {
    [$cliente, $chave] = $this->criarCliente();
    [$outro] = $this->criarCliente('Outro');
    $this->criarCotacaoComOpcoes($cliente);
    $this->criarCotacaoComOpcoes($cliente, '30140071');
    $this->criarCotacaoComOpcoes($outro);

    $r = $this->getJson('/api/v1/cotacoes', $this->auth($chave))->assertOk();

    expect($r->json('data'))->toHaveCount(2);
});

it('revelar credenciais continua so do dono, mesmo com TRA_01 liberada', function () {
    operadorDoPortal($this, ['TRA_01' => ['ver', 'editar']]);

    $this->postJson('/api/v1/portal/transportadoras/jadlog/credenciais', ['password' => 'senha-de-teste-123'])
        ->assertStatus(403)->assertJsonPath('erro.codigo', 'proibido');
});

// ---------------------------------------------------------------- gerenciar permissoes

it('o dono lista o catalogo para montar a tela', function () {
    donoDoPortal($this);

    $r = $this->getJson('/api/v1/portal/permissoes/catalogo')->assertOk();

    expect($r->json('data'))->toHaveCount(5)->and($r->json('data.0.aplicacoes.0.codigo'))->toBe('COT_01');
});

it('o dono define as permissoes do operador (troca tudo) e le de volta', function () {
    [$cliente] = donoDoPortal($this);
    $operador = $this->criarUsuario($cliente, PapelUsuario::OperadorTenant);

    $this->putJson("/api/v1/portal/usuarios/{$operador->id}/permissoes", ['permissoes' => [
        ['aplicacao' => 'COT_01', 'acoes' => ['criar']],
        ['aplicacao' => 'OPE_02', 'acoes' => ['ver', 'ver', 'etiqueta']],
    ]])->assertOk()->assertJsonPath('data.permissoes.1.acoes', ['ver', 'etiqueta']);

    $this->putJson("/api/v1/portal/usuarios/{$operador->id}/permissoes", ['permissoes' => [['aplicacao' => 'TRA_01', 'acoes' => ['ver']]]])->assertOk();

    $this->getJson("/api/v1/portal/usuarios/{$operador->id}/permissoes")->assertOk()
        ->assertJsonCount(1, 'data.permissoes')->assertJsonPath('data.permissoes.0.aplicacao', 'TRA_01');

    $this->putJson("/api/v1/portal/usuarios/{$operador->id}/permissoes", ['permissoes' => []])->assertOk();
    expect($operador->permissoes()->count())->toBe(0);
});

it('rejeita aplicacao ou acao que nao existe', function (array $permissoes) {
    [$cliente] = donoDoPortal($this);
    $operador = $this->criarUsuario($cliente, PapelUsuario::OperadorTenant);

    $this->putJson("/api/v1/portal/usuarios/{$operador->id}/permissoes", ['permissoes' => $permissoes])
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'permissao_invalida');
})->with([
    'aplicacao inexistente' => [[['aplicacao' => 'XXX_99', 'acoes' => ['ver']]]],
    'acao que a aplicacao nao tem' => [[['aplicacao' => 'COT_01', 'acoes' => ['excluir']]]],
]);

it('exige o campo permissoes e o formato certo', function () {
    [$cliente] = donoDoPortal($this);
    $operador = $this->criarUsuario($cliente, PapelUsuario::OperadorTenant);

    $this->putJson("/api/v1/portal/usuarios/{$operador->id}/permissoes", [])->assertStatus(422);
    $this->putJson("/api/v1/portal/usuarios/{$operador->id}/permissoes", ['permissoes' => [['aplicacao' => 'COT_01']]])->assertStatus(422);
});

it('so mexe em operador do proprio cliente: dono e outro cliente dao 404', function () {
    [$cliente, $dono] = donoDoPortal($this);
    [$outro] = $this->criarCliente('Outro');
    $estranho = $this->criarUsuario($outro, PapelUsuario::OperadorTenant);

    $this->getJson("/api/v1/portal/usuarios/{$dono->id}/permissoes")->assertNotFound();
    $this->putJson("/api/v1/portal/usuarios/{$estranho->id}/permissoes", ['permissoes' => []])->assertNotFound();
});

it('operador sem ADM_02 nao gerencia permissoes', function () {
    [$cliente] = $this->criarCliente();
    $outro = $this->criarUsuario($cliente, PapelUsuario::OperadorTenant);
    operadorDoPortal($this, ['COT_01' => ['criar']], $cliente);

    $this->getJson('/api/v1/portal/permissoes/catalogo')->assertStatus(403);
    $this->putJson("/api/v1/portal/usuarios/{$outro->id}/permissoes", ['permissoes' => []])->assertStatus(403);
});

it('operador com ADM_02 so concede o que ele mesmo tem', function () {
    [$cliente] = $this->criarCliente();
    $outro = $this->criarUsuario($cliente, PapelUsuario::OperadorTenant);
    operadorDoPortal($this, ['ADM_02' => ['ver', 'editar'], 'COT_01' => ['criar']], $cliente);

    $this->putJson("/api/v1/portal/usuarios/{$outro->id}/permissoes", ['permissoes' => [['aplicacao' => 'COT_01', 'acoes' => ['criar']]]])->assertOk();

    $this->putJson("/api/v1/portal/usuarios/{$outro->id}/permissoes", ['permissoes' => [['aplicacao' => 'ADM_01', 'acoes' => ['criar']]]])
        ->assertStatus(403)->assertJsonPath('erro.codigo', 'permissao_acima_do_proprio');
    expect($outro->permissoes()->pluck('aplicacao_codigo')->all())->toBe(['COT_01']);
});

// ---------------------------------------------------------------- criar operador ja com permissoes

it('criar operador com permissoes ja libera; sem permissoes ele nasce sem nada', function () {
    donoDoPortal($this);

    $this->postJson('/api/v1/portal/usuarios', ['nome' => 'Ana Operadora', 'email' => 'ana@loja.com.br', 'password' => 'senha-longa-1234',
        'permissoes' => [['aplicacao' => 'OPE_01', 'acoes' => ['criar']]]])->assertCreated()
        ->assertJsonPath('data.permissoes.0.aplicacao', 'OPE_01');

    $this->postJson('/api/v1/portal/usuarios', ['nome' => 'Bia Operadora', 'email' => 'bia@loja.com.br', 'password' => 'senha-longa-1234'])
        ->assertCreated()->assertJsonPath('data.permissoes', []);
});

it('permissao invalida na criacao nao deixa o operador criado', function () {
    donoDoPortal($this);

    $this->postJson('/api/v1/portal/usuarios', ['nome' => 'Ana Operadora', 'email' => 'ana@loja.com.br', 'password' => 'senha-longa-1234',
        'permissoes' => [['aplicacao' => 'XXX', 'acoes' => ['ver']]]])->assertStatus(422);

    expect(Usuario::where('email', 'ana@loja.com.br')->exists())->toBeFalse();
});

it('GET /usuarios traz as permissoes dos operadores e acesso_total do dono', function () {
    [$cliente] = donoDoPortal($this);
    $this->liberar($this->criarUsuario($cliente, PapelUsuario::OperadorTenant), ['COT_02' => ['ver']]);

    $r = $this->getJson('/api/v1/portal/usuarios')->assertOk();
    $porPapel = collect($r->json('data'))->keyBy('papel');

    expect($porPapel['adm_tenant']['acesso_total'])->toBeTrue()
        ->and($porPapel['operador_tenant']['permissoes'])->toBe([['aplicacao' => 'COT_02', 'acoes' => ['ver']]]);
});

it('o comando permissoes:sincronizar roda', function () {
    $this->artisan('permissoes:sincronizar')->assertSuccessful();
});
