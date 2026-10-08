<?php

declare(strict_types=1);

use App\Enums\PapelUsuario;

const SENHA_PADRAO = 'senha-de-teste-123';

it('login ok devolve o usuario (sem senha) e grava ultimo_login_em', function () {
    [$cliente] = $this->criarCliente();
    $usuario = $this->criarUsuario($cliente, PapelUsuario::OperadorTenant);

    $r = $this->postJson('/api/v1/portal/login', ['email' => $usuario->email, 'password' => SENHA_PADRAO])
        ->assertOk()
        ->assertJsonPath('data.id', $usuario->id)
        ->assertJsonPath('data.email', $usuario->email)
        ->assertJsonPath('data.papel', 'operador_tenant');

    expect($r->getContent())->not->toContain('password')->not->toContain(SENHA_PADRAO)
        ->and($usuario->fresh()->ultimo_login_em)->not->toBeNull();
});

it('o cookie de sessao e HttpOnly e SameSite=Strict', function () {
    [$cliente] = $this->criarCliente();
    $usuario = $this->criarUsuario($cliente);

    $r = $this->postJson('/api/v1/portal/login', ['email' => $usuario->email, 'password' => SENHA_PADRAO])->assertOk();

    $cookie = collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('strict');
});

it('com o cookie do login o GET /portal/eu funciona', function () {
    [$cliente] = $this->criarCliente('Loja X');
    $usuario = $this->criarUsuario($cliente);

    entrarNoPortal($this, $usuario)->assertOk();

    $this->getJson('/api/v1/portal/eu')
        ->assertOk()
        ->assertJsonPath('data.email', $usuario->email)
        ->assertJsonPath('data.cliente.id', $cliente->id)
        ->assertJsonPath('data.cliente.nome', 'Loja X');
});

it('o login troca o id da sessao (session fixation)', function () {
    [$cliente] = $this->criarCliente();
    $usuario = $this->criarUsuario($cliente);

    $antes = session()->getId();
    $this->postJson('/api/v1/portal/login', ['email' => $usuario->email, 'password' => SENHA_PADRAO])->assertOk();

    expect(session()->getId())->not->toBe($antes);
});

it('logout (204) encerra a sessao', function () {
    [$cliente] = $this->criarCliente();
    entrarNoPortal($this, $this->criarUsuario($cliente))->assertOk();
    $this->getJson('/api/v1/portal/eu')->assertOk();

    esquecerAutenticacao($this);
    $this->postJson('/api/v1/portal/logout')->assertNoContent();

    esquecerAutenticacao($this);
    $this->getJson('/api/v1/portal/eu')->assertStatus(401);
});

it('rotas do portal sem login dao 401', function () {
    $this->getJson('/api/v1/portal/eu')->assertStatus(401)->assertJsonPath('erro.codigo', 'nao_autenticado');
    $this->postJson('/api/v1/portal/logout')->assertStatus(401);
    $this->getJson('/api/v1/portal/conta')->assertStatus(401);
    $this->getJson('/api/v1/portal/remessas')->assertStatus(401);
    $this->patchJson('/api/v1/portal/eu/senha', ['senha_atual' => 'x', 'nova_senha' => 'uma-senha-nova-123'])->assertStatus(401);
});

it('falhas de login dao o MESMO 401 (nao revela o motivo)', function () {
    [$cliente] = $this->criarCliente();
    $usuario = $this->criarUsuario($cliente);
    $bloqueado = $this->criarUsuario($cliente);
    $bloqueado->update(['ativo' => false]);
    [$desabilitado] = $this->criarCliente('Parado');
    $usuarioParado = $this->criarUsuario($desabilitado);
    $desabilitado->update(['ativo' => false]);

    $respostas = [
        'senha errada' => $this->postJson('/api/v1/portal/login', ['email' => $usuario->email, 'password' => 'senha-errada-123']),
        'email inexistente' => $this->postJson('/api/v1/portal/login', ['email' => 'ninguem@exemplo.com.br', 'password' => SENHA_PADRAO]),
        'usuario bloqueado' => $this->postJson('/api/v1/portal/login', ['email' => $bloqueado->email, 'password' => SENHA_PADRAO]),
        'cliente desabilitado' => $this->postJson('/api/v1/portal/login', ['email' => $usuarioParado->email, 'password' => SENHA_PADRAO]),
    ];

    $corpos = [];
    foreach ($respostas as $nome => $resposta) {
        $resposta->assertStatus(401)->assertJsonPath('erro.codigo', 'nao_autenticado');
        $corpo = $resposta->json();
        unset($corpo['request_id']);
        $corpos[$nome] = $corpo;
    }

    expect(array_unique(array_map('json_encode', $corpos)))->toHaveCount(1);
});

it('o login nao diferencia maiusculas e minusculas no e-mail', function () {
    [$cliente] = $this->criarCliente();
    $usuario = $this->criarUsuario($cliente, PapelUsuario::OperadorTenant, 'maria@exemplo.com.br');

    $this->postJson('/api/v1/portal/login', ['email' => 'MARIA@Exemplo.COM.br', 'password' => SENHA_PADRAO])
        ->assertOk()->assertJsonPath('data.id', $usuario->id);
});

it('bloquear o usuario derruba a sessao aberta na hora', function () {
    [$cliente] = $this->criarCliente();
    $usuario = $this->criarUsuario($cliente);
    entrarNoPortal($this, $usuario)->assertOk();
    $this->getJson('/api/v1/portal/eu')->assertOk();

    $usuario->update(['ativo' => false]);

    esquecerAutenticacao($this);
    $this->getJson('/api/v1/portal/eu')->assertStatus(401);
});

it('desabilitar o cliente derruba a sessao aberta na hora', function () {
    [$cliente] = $this->criarCliente();
    entrarNoPortal($this, $this->criarUsuario($cliente))->assertOk();
    $this->getJson('/api/v1/portal/eu')->assertOk();

    $cliente->update(['ativo' => false]);

    esquecerAutenticacao($this);
    $this->getJson('/api/v1/portal/eu')->assertStatus(401);
});

it('limita o login a 5 tentativas por e-mail (429)', function () {
    [$cliente] = $this->criarCliente();
    $usuario = $this->criarUsuario($cliente);

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/portal/login', ['email' => $usuario->email, 'password' => 'errada-'.$i])->assertStatus(401);
    }

    // a 6a tentativa e barrada, mesmo com a senha certa
    $r = $this->postJson('/api/v1/portal/login', ['email' => $usuario->email, 'password' => SENHA_PADRAO])->assertStatus(429);
    expect((int) $r->headers->get('Retry-After'))->toBeGreaterThan(0);

    // outro e-mail nao e afetado
    $outro = $this->criarUsuario($cliente);
    $this->postJson('/api/v1/portal/login', ['email' => $outro->email, 'password' => SENHA_PADRAO])->assertOk();
});

it('login valida o corpo (422)', function () {
    $this->postJson('/api/v1/portal/login', [])->assertStatus(422);
    $this->postJson('/api/v1/portal/login', ['email' => 'a@b.com'])->assertStatus(422);
});

// ---------------------------------------------------------------- trocar a senha

it('trocar a senha: atual errada da 401', function () {
    [$cliente] = $this->criarCliente();
    $usuario = $this->criarUsuario($cliente);
    $this->actingAs($usuario, 'portal');

    $this->patchJson('/api/v1/portal/eu/senha', ['senha_atual' => 'errada-errada', 'nova_senha' => 'uma-senha-nova-123'])->assertStatus(401);

    expect(Hash::check(SENHA_PADRAO, $usuario->fresh()->password))->toBeTrue();
});

it('trocar a senha: nova curta, igual a atual ou campo extra dao 422', function () {
    [$cliente] = $this->criarCliente();
    $this->actingAs($this->criarUsuario($cliente), 'portal');

    $this->patchJson('/api/v1/portal/eu/senha', ['senha_atual' => SENHA_PADRAO, 'nova_senha' => 'curta'])
        ->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['nova_senha']]]);
    $this->patchJson('/api/v1/portal/eu/senha', ['senha_atual' => SENHA_PADRAO, 'nova_senha' => SENHA_PADRAO])
        ->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['nova_senha']]]);
    $this->patchJson('/api/v1/portal/eu/senha', ['senha_atual' => SENHA_PADRAO, 'nova_senha' => 'uma-senha-nova-123', 'email' => 'x@y.com'])->assertStatus(422);
});

it('trocar a senha: ok da 204 e a antiga para de logar', function () {
    [$cliente] = $this->criarCliente();
    $usuario = $this->criarUsuario($cliente);
    $this->actingAs($usuario, 'portal');

    $this->patchJson('/api/v1/portal/eu/senha', ['senha_atual' => SENHA_PADRAO, 'nova_senha' => 'uma-senha-nova-123'])->assertNoContent();

    $this->postJson('/api/v1/portal/login', ['email' => $usuario->email, 'password' => SENHA_PADRAO])->assertStatus(401);
    $this->postJson('/api/v1/portal/login', ['email' => $usuario->email, 'password' => 'uma-senha-nova-123'])->assertOk();
});

// ---------------------------------------------------------------- as duas portas nao se misturam

it('a chave de API NAO funciona nas rotas do portal', function () {
    [, $chave] = $this->criarCliente();

    $this->getJson('/api/v1/portal/eu', $this->auth($chave))->assertStatus(401);
    $this->getJson('/api/v1/portal/conta', $this->auth($chave))->assertStatus(401);
    $this->getJson('/api/v1/portal/chaves', $this->auth($chave))->assertStatus(401);
});

it('o cookie do portal NAO funciona nas rotas da chave de API', function () {
    [$cliente] = $this->criarCliente();
    entrarNoPortal($this, $this->criarUsuario($cliente))->assertOk();
    $this->getJson('/api/v1/portal/eu')->assertOk(); // o cookie vale no portal

    esquecerAutenticacao($this);
    $this->getJson('/api/v1/conta')->assertStatus(401);
    $this->postJson('/api/v1/cotacoes', $this->dadosDeCotacao())->assertStatus(401);
});

it('as rotas da chave de API nao criam cookie de sessao', function () {
    [, $chave] = $this->criarCliente();

    $r = $this->getJson('/api/v1/conta', $this->auth($chave))->assertOk();

    expect($r->headers->getCookies())->toBeEmpty();
});
