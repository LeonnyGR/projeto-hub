<?php

declare(strict_types=1);

use App\Models\Cliente;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Administracao dos clientes pela API (/api/v1/admin/clientes), protegida pelo token de admin.

const TOKEN_ADMIN = 'token-de-admin-de-teste-com-mais-de-32-caracteres';

beforeEach(fn () => config(['frete.admin_token' => TOKEN_ADMIN]));

function admin(string $token = TOKEN_ADMIN): array
{
    return ['Authorization' => 'Bearer '.$token];
}

/** Corpo valido para criar um cliente com dono. */
function dadosDeCliente(array $mudar = []): array
{
    return array_replace_recursive([
        'nome' => 'Loja do Joao',
        'cnpj' => '12.345.678/0001-95',
        'dono' => ['nome' => 'Joao Dono', 'email' => 'Joao@Loja.com.br', 'password' => 'senha-bem-longa-123'],
    ], $mudar);
}

it('cria o cliente com o dono (e-mail em minusculas, papel adm_tenant)', function () {
    $r = $this->postJson('/api/v1/admin/clientes', dadosDeCliente(), admin())
        ->assertCreated()
        ->assertJsonPath('data.nome', 'Loja do Joao')
        ->assertJsonPath('data.cnpj', '12345678000195')
        ->assertJsonPath('data.ativo', true)
        ->assertJsonPath('data.dono.email', 'joao@loja.com.br')
        ->assertJsonPath('data.dono.papel', 'adm_tenant');

    expect($r->json('data.id'))->toMatch('/^[0-9a-f-]{36}$/');

    $cliente = Cliente::findOrFail($r->json('data.id'));
    expect($cliente->usuarios()->count())->toBe(1)->and($cliente->chaves()->count())->toBe(0);
});

it('o dono criado consegue entrar no portal', function () {
    $this->postJson('/api/v1/admin/clientes', dadosDeCliente(), admin())->assertCreated();

    entrarNoPortal($this, 'joao@loja.com.br', 'senha-bem-longa-123')
        ->assertOk()->assertJsonPath('data.papel', 'adm_tenant');
    $this->getJson('/api/v1/portal/eu')->assertOk()->assertJsonPath('data.cliente.nome', 'Loja do Joao');
});

it('nao mostra senha nem hash na resposta e guarda a senha so em hash', function () {
    $r = $this->postJson('/api/v1/admin/clientes', dadosDeCliente(), admin())->assertCreated();

    expect($r->getContent())->not->toContain('senha-bem-longa-123')->not->toContain('password')->not->toContain('secret');

    $linha = DB::table('usuarios')->where('email', 'joao@loja.com.br')->first();
    expect($linha->password)->not->toBe('senha-bem-longa-123')->and(Hash::check('senha-bem-longa-123', $linha->password))->toBeTrue();
    expect(DB::table('clientes')->first())->not->toHaveProperty('secret_hash');
});

it('o e-mail do dono e unico no sistema (qualquer cliente, qualquer caixa)', function () {
    $this->postJson('/api/v1/admin/clientes', dadosDeCliente(), admin())->assertCreated();

    $this->postJson('/api/v1/admin/clientes', dadosDeCliente(['nome' => 'Outra', 'cnpj' => $this->cnpjNovo(), 'dono' => ['email' => 'JOAO@loja.com.br']]), admin())
        ->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['dono.email']]]);
    expect(Cliente::count())->toBe(1);
});

it('valida o dono: obrigatorio, senha com minimo de 10 caracteres', function (array $dados, string $campo) {
    $this->postJson('/api/v1/admin/clientes', $dados, admin())
        ->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => [$campo]]]);
    expect(Cliente::count())->toBe(0)->and(Usuario::count())->toBe(0);
})->with([
    'sem dono' => [['nome' => 'Loja', 'cnpj' => '12345678000195'], 'dono'],
    'dono sem email' => [['nome' => 'Loja', 'cnpj' => '12345678000195', 'dono' => ['nome' => 'Joao', 'password' => 'senha-bem-longa-123']], 'dono.email'],
    'dono com email invalido' => [['nome' => 'Loja', 'cnpj' => '12345678000195', 'dono' => ['nome' => 'Joao', 'email' => 'nao-e-email', 'password' => 'senha-bem-longa-123']], 'dono.email'],
    'dono sem nome' => [['nome' => 'Loja', 'cnpj' => '12345678000195', 'dono' => ['email' => 'a@b.com.br', 'password' => 'senha-bem-longa-123']], 'dono.nome'],
    'senha curta' => [['nome' => 'Loja', 'cnpj' => '12345678000195', 'dono' => ['nome' => 'Joao', 'email' => 'a@b.com.br', 'password' => 'curta']], 'dono.password'],
]);

it('valida o cadastro do cliente', function (array $dados, string $campo) {
    $this->postJson('/api/v1/admin/clientes', $dados + ['dono' => ['nome' => 'Joao', 'email' => 'joao@loja.com.br', 'password' => 'senha-bem-longa-123']], admin())
        ->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => [$campo]]]);
    expect(Cliente::count())->toBe(0);
})->with([
    'sem nome' => [['cnpj' => '12345678000195'], 'nome'],
    'nome curto' => [['nome' => 'A', 'cnpj' => '12345678000195'], 'nome'],
    'sem cnpj' => [['nome' => 'Loja'], 'cnpj'],
    'cnpj com digito verificador errado' => [['nome' => 'Loja', 'cnpj' => '12345678000196'], 'cnpj'],
    'cnpj curto' => [['nome' => 'Loja', 'cnpj' => '123'], 'cnpj'],
    'cpf nao serve' => [['nome' => 'Loja', 'cnpj' => '52998224725'], 'cnpj'],
    'campo desconhecido' => [['nome' => 'Loja', 'cnpj' => '12345678000195', 'ativo' => false], 'ativo'],
    'cep nao existe no cadastro' => [['nome' => 'Loja', 'cnpj' => '12345678000195', 'cep_origem' => '01310100'], 'cep_origem'],
]);

it('lista e consulta clientes sem mostrar chave, senha nem hash', function () {
    [$a, $credenciais] = $this->criarCliente('Alfa');
    $this->criarCliente('Zeta');
    [, $segredo] = explode('.', $credenciais, 2);

    $r = $this->getJson('/api/v1/admin/clientes', admin())->assertOk();
    expect(collect($r->json('data'))->pluck('nome')->all())->toBe(['Alfa', 'Zeta'])
        ->and($r->getContent())->not->toContain($segredo)->not->toContain(hash('sha256', $segredo))->not->toContain('password');

    $this->getJson("/api/v1/admin/clientes/{$a->id}", admin())->assertOk()->assertJsonPath('data.id', $a->id);
    $this->getJson('/api/v1/admin/clientes/'.fake()->uuid(), admin())->assertStatus(404);
});

it('atualiza o nome, e desabilita e reabilita o cliente', function () {
    [$cliente, $credenciais] = $this->criarCliente('Loja Velha');

    $this->patchJson("/api/v1/admin/clientes/{$cliente->id}", ['nome' => 'Loja Nova'], admin())
        ->assertOk()->assertJsonPath('data.nome', 'Loja Nova');

    $this->patchJson("/api/v1/admin/clientes/{$cliente->id}", ['ativo' => false], admin())->assertOk()->assertJsonPath('data.ativo', false);
    $this->getJson('/api/v1/conta', $this->auth($credenciais))->assertStatus(401);

    $this->patchJson("/api/v1/admin/clientes/{$cliente->id}", ['ativo' => true], admin())->assertOk();
    $this->getJson('/api/v1/conta', $this->auth($credenciais))->assertOk();
});

it('nao existe mais a troca de secret (agora o dono cria e revoga chaves no portal)', function () {
    [$cliente] = $this->criarCliente();

    $this->postJson("/api/v1/admin/clientes/{$cliente->id}/secret", [], admin())->assertStatus(404);
});

it('exige o token de admin: sem token, token errado ou credenciais de cliente dao 401', function () {
    [, $credenciais] = $this->criarCliente();

    $this->getJson('/api/v1/admin/clientes')->assertStatus(401);
    $this->getJson('/api/v1/admin/clientes', admin('token-errado'))->assertStatus(401);
    $this->getJson('/api/v1/admin/clientes', $this->auth($credenciais))->assertStatus(401);
    $this->postJson('/api/v1/admin/clientes', ['nome' => 'Loja'])->assertStatus(401);
    expect(Cliente::count())->toBe(1);
});

it('o token de admin nao serve como login de cliente', function () {
    $this->getJson('/api/v1/conta', admin())->assertStatus(401);
});

it('sem ADMIN_API_TOKEN configurado (ou curto) a administracao fica desligada', function (?string $token) {
    config(['frete.admin_token' => $token]);

    $this->getJson('/api/v1/admin/clientes', admin($token ?? ''))->assertStatus(401);
})->with([[null], [''], ['curto']]);

it('muitas falhas seguidas bloqueiam o IP (429)', function () {
    config(['frete.limites.auth_falhas_ip' => 3]);

    for ($i = 0; $i < 3; $i++) {
        $this->getJson('/api/v1/admin/clientes', admin('errado'))->assertStatus(401);
    }

    $this->getJson('/api/v1/admin/clientes', admin())->assertStatus(429);
});

it('nao deixa cadastrar dois clientes com o mesmo CNPJ (nem com mascara diferente)', function () {
    $this->postJson('/api/v1/admin/clientes', dadosDeCliente(['nome' => 'Loja A', 'cnpj' => '12345678000195']), admin())->assertCreated();

    $this->postJson('/api/v1/admin/clientes', dadosDeCliente(['nome' => 'Loja B', 'cnpj' => '12.345.678/0001-95', 'dono' => ['email' => 'outro@loja.com.br']]), admin())
        ->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['cnpj']]]);
    expect(Cliente::count())->toBe(1);
});

it('atualiza o CNPJ (o proprio CNPJ do cliente nao conta como repetido, o de outro sim)', function () {
    [$a] = $this->criarCliente('Alfa', '12345678000195');
    [$b] = $this->criarCliente('Beta');

    $this->patchJson("/api/v1/admin/clientes/{$a->id}", ['cnpj' => '12.345.678/0001-95'], admin())->assertOk();
    $this->patchJson("/api/v1/admin/clientes/{$b->id}", ['cnpj' => '12345678000195'], admin())->assertStatus(422);
    $this->patchJson("/api/v1/admin/clientes/{$b->id}", ['cnpj' => '11222333000181'], admin())->assertOk()->assertJsonPath('data.cnpj', '11222333000181');
});
