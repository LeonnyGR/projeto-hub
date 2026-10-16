<?php

declare(strict_types=1);

use App\Enums\PapelUsuario;
use App\Models\Usuario;

const SENHA_NOVA = 'senha-nova-do-operador-1';

/** Cliente com o dono logado (actingAs). Devolve [cliente, dono]. */
function portalComDono($teste): array
{
    [$cliente] = $teste->criarCliente();
    $dono = $teste->criarUsuario($cliente, PapelUsuario::AdmTenant);
    $teste->actingAs($dono, 'portal');

    return [$cliente, $dono];
}

function dadosDeOperador(array $mudar = []): array
{
    return array_merge(['nome' => 'Maria Operadora', 'email' => 'Maria@Loja.com.br', 'password' => SENHA_NOVA], $mudar);
}

it('o dono cria um operador (201, sem senha, e-mail em minusculas)', function () {
    [$cliente] = portalComDono($this);

    $r = $this->postJson('/api/v1/portal/usuarios', dadosDeOperador())
        ->assertCreated()
        ->assertJsonPath('data.papel', 'operador_tenant')
        ->assertJsonPath('data.email', 'maria@loja.com.br')
        ->assertJsonPath('data.ativo', true);

    expect($r->getContent())->not->toContain('password')->not->toContain(SENHA_NOVA);

    $operador = Usuario::findOrFail($r->json('data.id'));
    expect($operador->cliente_id)->toBe($cliente->id)
        ->and($operador->papel)->toBe(PapelUsuario::OperadorTenant)
        ->and($operador->password)->not->toBe(SENHA_NOVA);
});

it('valida a criacao do operador', function (array $mudar, string $campo) {
    portalComDono($this);

    $this->postJson('/api/v1/portal/usuarios', dadosDeOperador($mudar))
        ->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => [$campo]]]);
})->with([
    'senha curta' => [['password' => 'curta'], 'password'],
    'sem nome' => [['nome' => ''], 'nome'],
    'email invalido' => [['email' => 'nao-e-email'], 'email'],
    'campo papel nao existe' => [['papel' => 'adm_tenant'], 'papel'],
]);

it('e-mail repetido da 422 (mesmo com outra caixa e em outro cliente)', function () {
    portalComDono($this);
    [$outro] = $this->criarCliente('Outro');
    $this->criarUsuario($outro, PapelUsuario::OperadorTenant, 'maria@loja.com.br');

    $this->postJson('/api/v1/portal/usuarios', dadosDeOperador())
        ->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['email']]]);
});

it('a lista traz so os usuarios do proprio cliente', function () {
    [$cliente, $dono] = portalComDono($this);
    $operador = $this->criarUsuario($cliente, PapelUsuario::OperadorTenant);
    [$outro] = $this->criarCliente('Outro');
    $estranho = $this->criarUsuario($outro, PapelUsuario::OperadorTenant);

    $r = $this->getJson('/api/v1/portal/usuarios')->assertOk();

    $ids = collect($r->json('data'))->pluck('id');
    expect($ids)->toContain($dono->id)->toContain($operador->id)->not->toContain($estranho->id)
        ->and($r->getContent())->not->toContain('password');
});

it('o dono bloqueia e libera o operador (bloqueado nao loga)', function () {
    [$cliente] = portalComDono($this);
    $operador = $this->criarUsuario($cliente, PapelUsuario::OperadorTenant);

    $this->patchJson("/api/v1/portal/usuarios/{$operador->id}", ['ativo' => false])
        ->assertOk()->assertJsonPath('data.ativo', false);
    $this->postJson('/api/v1/portal/login', ['email' => $operador->email, 'password' => 'senha-de-teste-123'])->assertStatus(401);

    $this->patchJson("/api/v1/portal/usuarios/{$operador->id}", ['ativo' => true])->assertOk()->assertJsonPath('data.ativo', true);
    $this->postJson('/api/v1/portal/login', ['email' => $operador->email, 'password' => 'senha-de-teste-123'])->assertOk();
});

it('o dono redefine a senha e muda o nome do operador', function () {
    [$cliente] = portalComDono($this);
    $operador = $this->criarUsuario($cliente, PapelUsuario::OperadorTenant);

    $this->patchJson("/api/v1/portal/usuarios/{$operador->id}", ['password' => SENHA_NOVA, 'nome' => 'Novo Nome'])
        ->assertOk()->assertJsonPath('data.nome', 'Novo Nome');

    $this->postJson('/api/v1/portal/login', ['email' => $operador->email, 'password' => 'senha-de-teste-123'])->assertStatus(401);
    $this->postJson('/api/v1/portal/login', ['email' => $operador->email, 'password' => SENHA_NOVA])->assertOk();
});

it('o PATCH valida: senha curta, e-mail e papel nao mudam', function () {
    [$cliente] = portalComDono($this);
    $operador = $this->criarUsuario($cliente, PapelUsuario::OperadorTenant);

    $this->patchJson("/api/v1/portal/usuarios/{$operador->id}", ['password' => 'curta'])->assertStatus(422);
    $this->patchJson("/api/v1/portal/usuarios/{$operador->id}", ['email' => 'outro@x.com.br'])->assertStatus(422);
    $this->patchJson("/api/v1/portal/usuarios/{$operador->id}", ['papel' => 'adm_tenant'])->assertStatus(422);

    expect($operador->fresh()->papel)->toBe(PapelUsuario::OperadorTenant);
});

it('nao altera o dono nem usuario de outro cliente (404)', function () {
    [$cliente, $dono] = portalComDono($this);
    [$outro] = $this->criarCliente('Outro');
    $estranho = $this->criarUsuario($outro, PapelUsuario::OperadorTenant);

    $this->patchJson("/api/v1/portal/usuarios/{$dono->id}", ['ativo' => false])->assertStatus(404);
    $this->patchJson("/api/v1/portal/usuarios/{$estranho->id}", ['ativo' => false])->assertStatus(404);

    expect($dono->fresh()->ativo)->toBeTrue()->and($estranho->fresh()->ativo)->toBeTrue();
});

it('operador recebe 403 em listar, criar e alterar usuarios', function () {
    [$cliente] = $this->criarCliente();
    $operador = $this->criarUsuario($cliente, PapelUsuario::OperadorTenant);
    $outro = $this->criarUsuario($cliente, PapelUsuario::OperadorTenant);
    $this->actingAs($operador, 'portal');

    $this->getJson('/api/v1/portal/usuarios')->assertStatus(403)->assertJsonPath('erro.codigo', 'sem_permissao');
    $this->postJson('/api/v1/portal/usuarios', dadosDeOperador())->assertStatus(403)->assertJsonPath('erro.codigo', 'sem_permissao');
    $this->patchJson("/api/v1/portal/usuarios/{$outro->id}", ['ativo' => false])->assertStatus(403)->assertJsonPath('erro.codigo', 'sem_permissao');

    expect($outro->fresh()->ativo)->toBeTrue()->and(Usuario::where('email', 'maria@loja.com.br')->exists())->toBeFalse();
});

it('sem login as rotas de usuarios dao 401', function () {
    $this->getJson('/api/v1/portal/usuarios')->assertStatus(401);
    $this->postJson('/api/v1/portal/usuarios', dadosDeOperador())->assertStatus(401);
});

it('o operador criado consegue logar e usar o dia a dia', function () {
    [$cliente] = portalComDono($this);
    $this->configurarJadlog($cliente);
    $this->postJson('/api/v1/portal/usuarios', dadosDeOperador(['permissoes' => [['aplicacao' => 'COT_01', 'acoes' => ['criar']]]]))->assertCreated();

    // sai do "actingAs" do dono e entra de verdade como operador
    esquecerAutenticacao($this);
    app('auth')->shouldUse('portal');
    entrarNoPortal($this, 'maria@loja.com.br', SENHA_NOVA)->assertOk()->assertJsonPath('data.papel', 'operador_tenant');

    $this->getJson('/api/v1/portal/eu')->assertOk()->assertJsonPath('data.email', 'maria@loja.com.br');
    $this->getJson('/api/v1/portal/conta')->assertOk()->assertJsonPath('data.id', $cliente->id);
    $this->postJson('/api/v1/portal/cotacoes', $this->dadosDeCotacao())->assertCreated();
});
