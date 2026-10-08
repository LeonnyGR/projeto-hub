<?php

declare(strict_types=1);

use App\Enums\PapelUsuario;
use Illuminate\Support\Facades\Log;

// O dono ve as credenciais por inteiro no portal, confirmando a propria senha.

const SENHA_DO_USUARIO = 'senha-de-teste-123';
const TOKEN_INTEIRO = 'token-inteiro-da-jadlog-9876';

/** Cliente com as duas transportadoras e um usuario logado no portal. Devolve [cliente, usuario]. */
function donoComCredenciais($teste, PapelUsuario $papel = PapelUsuario::AdmTenant): array
{
    [$cliente] = $teste->criarCliente();
    $teste->configurarJadlog($cliente, TOKEN_INTEIRO);
    $teste->configurarCorreios($cliente);
    $usuario = $teste->criarUsuario($cliente, $papel);
    $teste->actingAs($usuario, 'portal');

    return [$cliente, $usuario];
}

it('o dono ve as credenciais inteiras com a senha certa, sem cache', function () {
    donoComCredenciais($this);

    $this->postJson('/api/v1/portal/transportadoras/jadlog/credenciais', ['password' => SENHA_DO_USUARIO])
        ->assertOk()
        ->assertJsonPath('data.transportadora', 'jadlog')
        ->assertJsonPath('data.credenciais.token', TOKEN_INTEIRO)
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->postJson('/api/v1/portal/transportadoras/correios/credenciais', ['password' => SENHA_DO_USUARIO])
        ->assertOk()
        ->assertJsonPath('data.credenciais', ['usuario' => 'usuario-correios', 'senha' => 'senha-secreta-dos-correios', 'cartao_postagem' => '0012345678']);
});

it('senha errada da 422 senha_incorreta e nao mostra nada', function () {
    donoComCredenciais($this);

    $r = $this->postJson('/api/v1/portal/transportadoras/jadlog/credenciais', ['password' => 'senha-errada'])
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'senha_incorreta');

    expect($r->getContent())->not->toContain(TOKEN_INTEIRO);
});

it('sem password da 422 de validacao', function () {
    donoComCredenciais($this);

    $this->postJson('/api/v1/portal/transportadoras/jadlog/credenciais', [])
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'validacao_falhou');
});

it('transportadora ainda nao cadastrada da 404 transportadora_nao_cadastrada', function () {
    [$cliente] = $this->criarCliente();
    $this->actingAs($this->criarUsuario($cliente, PapelUsuario::AdmTenant), 'portal');

    $this->postJson('/api/v1/portal/transportadoras/jadlog/credenciais', ['password' => SENHA_DO_USUARIO])
        ->assertNotFound()->assertJsonPath('erro.codigo', 'transportadora_nao_cadastrada');
});

it('transportadora desconhecida na URL da 404', function () {
    donoComCredenciais($this);

    $this->postJson('/api/v1/portal/transportadoras/fedex/credenciais', ['password' => SENHA_DO_USUARIO])->assertNotFound();
});

it('operador recebe 403 proibido, mesmo com a senha certa', function () {
    donoComCredenciais($this, PapelUsuario::OperadorTenant);

    $r = $this->postJson('/api/v1/portal/transportadoras/jadlog/credenciais', ['password' => SENHA_DO_USUARIO])
        ->assertForbidden()->assertJsonPath('erro.codigo', 'proibido');

    expect($r->getContent())->not->toContain(TOKEN_INTEIRO);
});

it('sem login da 401', function () {
    $this->postJson('/api/v1/portal/transportadoras/jadlog/credenciais', ['password' => SENHA_DO_USUARIO])->assertUnauthorized();
});

it('a rota nao existe na porta da chave de API', function () {
    [$cliente, $chave] = $this->criarCliente();
    $this->configurarJadlog($cliente, TOKEN_INTEIRO);

    $r = $this->postJson('/api/v1/transportadoras/jadlog/credenciais', ['password' => 'qualquer'], $this->auth($chave));

    expect($r->status())->toBeIn([404, 405]);
    expect($r->getContent())->not->toContain(TOKEN_INTEIRO);
});

it('cada dono ve so as credenciais do proprio cliente', function () {
    [$clienteA] = $this->criarCliente('A');
    $this->configurarJadlog($clienteA, 'token-do-cliente-A-1111');
    [$clienteB] = $this->criarCliente('B');
    $this->configurarJadlog($clienteB, 'token-do-cliente-B-2222');

    $this->actingAs($this->criarUsuario($clienteA, PapelUsuario::AdmTenant), 'portal');
    $this->postJson('/api/v1/portal/transportadoras/jadlog/credenciais', ['password' => SENHA_DO_USUARIO])
        ->assertOk()->assertJsonPath('data.credenciais.token', 'token-do-cliente-A-1111');

    esquecerAutenticacao($this);
    $this->actingAs($this->criarUsuario($clienteB, PapelUsuario::AdmTenant), 'portal');
    $r = $this->postJson('/api/v1/portal/transportadoras/jadlog/credenciais', ['password' => SENHA_DO_USUARIO])
        ->assertOk()->assertJsonPath('data.credenciais.token', 'token-do-cliente-B-2222');

    expect($r->getContent())->not->toContain('token-do-cliente-A-1111');
});

it('limita a consultas por minuto: a sexta da 429', function () {
    donoComCredenciais($this);

    for ($i = 0; $i < config('frete.limites.revelar'); $i++) {
        $this->postJson('/api/v1/portal/transportadoras/jadlog/credenciais', ['password' => SENHA_DO_USUARIO])->assertOk();
    }

    $this->postJson('/api/v1/portal/transportadoras/jadlog/credenciais', ['password' => SENHA_DO_USUARIO])->assertStatus(429);
});

it('registra no log quem viu, sem gravar o segredo', function () {
    Log::spy();
    [$cliente, $usuario] = donoComCredenciais($this);

    $this->postJson('/api/v1/portal/transportadoras/jadlog/credenciais', ['password' => SENHA_DO_USUARIO])->assertOk();

    Log::shouldHaveReceived('notice')->once()->withArgs(function ($mensagem, $contexto = []) use ($cliente, $usuario) {
        return $mensagem === 'Credenciais de transportadora reveladas'
            && $contexto['cliente_id'] === $cliente->id
            && $contexto['usuario_id'] === $usuario->id
            && $contexto['transportadora'] === 'jadlog'
            && ! str_contains(json_encode($contexto), TOKEN_INTEIRO)
            && ! str_contains(json_encode($contexto), SENHA_DO_USUARIO);
    });
});

it('senha errada nao grava o log de revelacao', function () {
    Log::spy();
    donoComCredenciais($this);

    $this->postJson('/api/v1/portal/transportadoras/jadlog/credenciais', ['password' => 'senha-errada'])->assertStatus(422);

    Log::shouldNotHaveReceived('notice', ['Credenciais de transportadora reveladas', Mockery::any()]);
});
