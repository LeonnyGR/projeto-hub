<?php

declare(strict_types=1);

use App\Enums\PapelUsuario;

// Editar uma transportadora ja cadastrada: o cliente muda so o que quer (inclusive um unico campo da credencial)
// e consulta uma transportadora so para preencher o formulario.

function correiosCompleto(): array
{
    return [
        'credenciais' => ['usuario' => 'usuario-correios', 'senha' => 'senha-secreta-dos-correios', 'cartao_postagem' => '0012345678'],
        'configuracao' => ['contrato' => '9912345678', 'servicos' => [['codigo' => '03220', 'nome' => 'SEDEX'], ['codigo' => '04669', 'nome' => 'PAC']]],
    ];
}

it('muda so um campo da credencial: o resto continua como estava', function () {
    [$cliente, $chave] = $this->criarCliente();
    $this->putJson('/api/v1/transportadoras/correios', correiosCompleto(), $this->auth($chave))->assertCreated();

    $dados = correiosCompleto();
    $dados['credenciais'] = ['senha' => 'nova-senha-dos-correios']; // so a senha

    $this->putJson('/api/v1/transportadoras/correios', $dados, $this->auth($chave))->assertOk();

    expect($cliente->credenciais()->where('transportadora', 'correios')->first()->credenciais)->toBe([
        'usuario' => 'usuario-correios',
        'senha' => 'nova-senha-dos-correios',
        'cartao_postagem' => '0012345678',
    ]);
});

it('campo de credencial em branco significa "nao mudar"', function () {
    [$cliente, $chave] = $this->criarCliente();
    $this->putJson('/api/v1/transportadoras/correios', correiosCompleto(), $this->auth($chave))->assertCreated();

    $dados = correiosCompleto();
    $dados['credenciais'] = ['usuario' => '', 'senha' => null, 'cartao_postagem' => '0099999999'];

    $this->putJson('/api/v1/transportadoras/correios', $dados, $this->auth($chave))->assertOk();

    expect($cliente->credenciais()->where('transportadora', 'correios')->first()->credenciais)->toBe([
        'usuario' => 'usuario-correios',
        'senha' => 'senha-secreta-dos-correios',
        'cartao_postagem' => '0099999999',
    ]);
});

it('muda a configuracao (servicos contratados) sem mandar nenhuma credencial', function () {
    [$cliente, $chave] = $this->criarCliente();
    $this->putJson('/api/v1/transportadoras/correios', correiosCompleto(), $this->auth($chave))->assertCreated();

    $dados = correiosCompleto();
    unset($dados['credenciais']);
    $dados['configuracao']['servicos'] = [['codigo' => '04669', 'nome' => 'PAC']]; // agora so PAC

    $this->putJson('/api/v1/transportadoras/correios', $dados, $this->auth($chave))->assertOk();

    $credencial = $cliente->credenciais()->where('transportadora', 'correios')->first();
    expect($credencial->configuracao['servicos'])->toEqual([['codigo' => '04669', 'nome' => 'PAC']])
        ->and($credencial->credenciais['senha'])->toBe('senha-secreta-dos-correios');
});

it('valor novo e invalido na edicao da credencial da 422 e nada muda', function () {
    [$cliente, $chave] = $this->criarCliente();
    $this->putJson('/api/v1/transportadoras/correios', correiosCompleto(), $this->auth($chave))->assertCreated();

    $dados = correiosCompleto();
    $dados['credenciais'] = ['cartao_postagem' => 'abc'];

    $this->putJson('/api/v1/transportadoras/correios', $dados, $this->auth($chave))
        ->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['credenciais.cartao_postagem']]]);

    expect($cliente->credenciais()->first()->credenciais['cartao_postagem'])->toBe('0012345678');
});

it('no primeiro cadastro todas as credenciais continuam obrigatorias', function () {
    [, $chave] = $this->criarCliente();

    $dados = correiosCompleto();
    $dados['credenciais'] = ['senha' => 'so-a-senha-nao-basta'];

    $this->putJson('/api/v1/transportadoras/correios', $dados, $this->auth($chave))
        ->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['credenciais.usuario', 'credenciais.cartao_postagem']]]);
});

it('consulta uma transportadora (para preencher o formulario): configuracao inteira e credenciais so mascaradas', function () {
    [, $chave] = $this->criarCliente();
    $this->putJson('/api/v1/transportadoras/correios', correiosCompleto(), $this->auth($chave))->assertCreated();

    $r = $this->getJson('/api/v1/transportadoras/correios', $this->auth($chave))->assertOk()
        ->assertJsonPath('data.transportadora', 'correios')
        ->assertJsonPath('data.configuracao.contrato', '9912345678')
        ->assertJsonPath('data.configuracao.servicos.1.codigo', '04669');

    expect($r->getContent())->not->toContain('senha-secreta-dos-correios')->not->toContain('usuario-correios');
});

it('consultar uma transportadora que nao foi cadastrada da 404; desconhecida tambem', function () {
    [, $chave] = $this->criarCliente();

    $this->getJson('/api/v1/transportadoras/correios', $this->auth($chave))
        ->assertStatus(404)->assertJsonPath('erro.codigo', 'transportadora_nao_cadastrada');
    $this->getJson('/api/v1/transportadoras/fedex', $this->auth($chave))->assertStatus(404);
});

it('cada cliente consulta so a transportadora dele', function () {
    [, $chaveA] = $this->criarCliente('Cliente A');
    [, $chaveB] = $this->criarCliente('Cliente B');
    $this->putJson('/api/v1/transportadoras/correios', correiosCompleto(), $this->auth($chaveA))->assertCreated();

    $this->getJson('/api/v1/transportadoras/correios', $this->auth($chaveB))->assertStatus(404);
});

it('no portal: o dono edita um campo e o operador so consulta', function () {
    [$cliente] = $this->criarCliente();
    $dono = $this->criarUsuario($cliente, PapelUsuario::AdmTenant);
    $operador = $this->criarUsuario($cliente, PapelUsuario::OperadorTenant);

    $this->actingAs($dono, 'portal');
    $this->putJson('/api/v1/portal/transportadoras/correios', correiosCompleto())->assertCreated();

    $dados = correiosCompleto();
    $dados['credenciais'] = ['senha' => 'senha-nova-do-portal'];
    $this->putJson('/api/v1/portal/transportadoras/correios', $dados)->assertOk();
    expect($cliente->credenciais()->first()->credenciais['senha'])->toBe('senha-nova-do-portal');

    $this->actingAs($operador, 'portal');
    $this->getJson('/api/v1/portal/transportadoras/correios')->assertOk();
    $this->putJson('/api/v1/portal/transportadoras/correios', $dados)->assertStatus(403)->assertJsonPath('erro.codigo', 'proibido');
});
