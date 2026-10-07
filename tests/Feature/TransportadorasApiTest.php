<?php

declare(strict_types=1);

use App\Models\CredencialTransportadora;
use Illuminate\Support\Facades\DB;

// Cadastro das transportadoras do B2C por API (PUT /transportadoras/{t}) e liga/desliga (PATCH).

function dadosDaJadlog(array $mudar = []): array
{
    return array_replace_recursive([
        'credenciais' => ['token' => 'token-secreto-da-jadlog'],
        'configuracao' => [
            'tomador' => [
                'nome' => 'Bartofil Comercio Ltda', 'documento' => '12.345.678/0001-95', 'telefone' => '(11) 3333-4444',
                'endereco' => ['cep' => '01001-000', 'logradouro' => 'Praca da Se', 'numero' => '1', 'bairro' => 'Se', 'cidade' => 'Sao Paulo', 'uf' => 'sp'],
            ],
            'cod_cliente' => '000123',
            'tipo_coleta' => 'K',
            'servicos' => [['codigo' => '3', 'nome' => 'Package'], ['codigo' => '0', 'nome' => 'Expresso']],
        ],
    ], $mudar);
}

function dadosDosCorreios(): array
{
    return [
        'credenciais' => ['usuario' => 'usuario-correios', 'senha' => 'senha-secreta', 'cartao_postagem' => '0012345678'],
        'configuracao' => ['contrato' => '9912345678', 'servicos' => [['codigo' => '03220', 'nome' => 'SEDEX'], ['codigo' => '04669', 'nome' => 'PAC']]],
    ];
}

it('cadastra a Jadlog (201): dados normalizados, segredo criptografado e fora da resposta', function () {
    [$cliente, $chave] = $this->criarCliente();

    $r = $this->putJson('/api/v1/transportadoras/jadlog', dadosDaJadlog(), $this->auth($chave))
        ->assertCreated()
        ->assertJsonPath('data.transportadora', 'jadlog')
        ->assertJsonPath('data.ativa', true)
        ->assertJsonPath('data.credenciais_cadastradas', true);

    expect($r->getContent())->not->toContain('token-secreto-da-jadlog');

    $cred = $cliente->credenciais()->where('transportadora', 'jadlog')->firstOrFail();
    expect($cred->credenciais)->toBe(['token' => 'token-secreto-da-jadlog'])
        ->and($cred->configuracao['tomador']['documento'])->toBe('12345678000195')
        ->and($cred->configuracao['tomador']['telefone'])->toBe('1133334444')
        ->and($cred->configuracao['tomador']['endereco']['cep'])->toBe('01001000')
        ->and($cred->configuracao['tomador']['endereco']['uf'])->toBe('SP');

    expect(DB::table('credenciais_transportadoras')->where('id', $cred->id)->value('credenciais'))->not->toContain('token-secreto-da-jadlog');
});

it('cadastra os Correios', function () {
    [$cliente, $chave] = $this->criarCliente();

    $this->putJson('/api/v1/transportadoras/correios', dadosDosCorreios(), $this->auth($chave))->assertCreated();

    expect($cliente->credenciais()->where('transportadora', 'correios')->first()->credenciais['usuario'])->toBe('usuario-correios');
});

it('PUT de novo atualiza (200) e uma transportadora cadastrada ja entra nas cotacoes', function () {
    [$cliente, $chave] = $this->criarCliente();
    [$seller] = [$cliente->sellers()->create(['nome' => 'Loja', 'cep_origem' => '01310100', 'ativo' => true])];

    $this->putJson('/api/v1/transportadoras/jadlog', dadosDaJadlog(), $this->auth($chave))->assertCreated();
    $this->putJson('/api/v1/transportadoras/jadlog', dadosDaJadlog(['configuracao' => ['cod_cliente' => '999']]), $this->auth($chave))
        ->assertOk()->assertJsonPath('data.configuracao.cod_cliente', '999');

    expect($cliente->credenciais()->count())->toBe(1);

    $this->postJson("/api/v1/sellers/{$seller->id}/cotacoes", $this->dadosDeCotacao(), $this->auth($chave))
        ->assertCreated()->assertJsonPath('data.falhas', []);
});

it('editar sem mandar credenciais mantem o segredo que ja estava salvo', function () {
    [$cliente, $chave] = $this->criarCliente();
    $this->putJson('/api/v1/transportadoras/jadlog', dadosDaJadlog(), $this->auth($chave))->assertCreated();

    $novo = dadosDaJadlog(['configuracao' => ['cod_cliente' => '777']]);
    unset($novo['credenciais']);
    $this->putJson('/api/v1/transportadoras/jadlog', $novo, $this->auth($chave))->assertOk();

    $cred = $cliente->credenciais()->where('transportadora', 'jadlog')->first();
    expect($cred->credenciais)->toBe(['token' => 'token-secreto-da-jadlog'])->and($cred->configuracao['cod_cliente'])->toBe('777');
});

it('primeiro cadastro exige as credenciais', function () {
    [, $chave] = $this->criarCliente();
    $dados = dadosDaJadlog();
    unset($dados['credenciais']);

    $this->putJson('/api/v1/transportadoras/jadlog', $dados, $this->auth($chave))
        ->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['credenciais']]]);
});

it('valida os dados com as regras da transportadora', function (array $mudar, string $campo) {
    [, $chave] = $this->criarCliente();

    $this->putJson('/api/v1/transportadoras/jadlog', dadosDaJadlog($mudar), $this->auth($chave))
        ->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => [$campo]]]);
    expect(CredencialTransportadora::count())->toBe(0);
})->with([
    'sem token' => [['credenciais' => ['token' => '']], 'credenciais.token'],
    'cnpj invalido' => [['configuracao' => ['tomador' => ['documento' => '11111111111111']]], 'configuracao.tomador.documento'],
    'coleta invalida' => [['configuracao' => ['tipo_coleta' => 'X']], 'configuracao.tipo_coleta'],
]);

it('rejeita campo desconhecido', function () {
    [, $chave] = $this->criarCliente();

    $this->putJson('/api/v1/transportadoras/correios', dadosDosCorreios() + ['campo_que_nao_existe' => 1], $this->auth($chave))->assertStatus(422);
});

it('transportadora desconhecida da 404', function () {
    [, $chave] = $this->criarCliente();

    $this->putJson('/api/v1/transportadoras/fedex', dadosDosCorreios(), $this->auth($chave))->assertStatus(404);
});

it('liga e desliga a transportadora; desligada nao entra na cotacao', function () {
    [$cliente, $chave] = $this->criarCliente();
    $seller = $cliente->sellers()->create(['nome' => 'Loja', 'cep_origem' => '01310100', 'ativo' => true]);
    $this->putJson('/api/v1/transportadoras/jadlog', dadosDaJadlog(), $this->auth($chave))->assertCreated();

    $this->patchJson('/api/v1/transportadoras/jadlog', ['ativa' => false], $this->auth($chave))->assertOk()->assertJsonPath('data.ativa', false);
    $this->postJson("/api/v1/sellers/{$seller->id}/cotacoes", $this->dadosDeCotacao(), $this->auth($chave))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'sem_transportadoras');

    $this->patchJson('/api/v1/transportadoras/jadlog', ['ativa' => true], $this->auth($chave))->assertOk()->assertJsonPath('data.ativa', true);
});

it('nao liga transportadora que nao foi cadastrada', function () {
    [, $chave] = $this->criarCliente();

    $this->patchJson('/api/v1/transportadoras/jadlog', ['ativa' => true], $this->auth($chave))
        ->assertStatus(404)->assertJsonPath('erro.codigo', 'transportadora_nao_cadastrada');
    $this->patchJson('/api/v1/transportadoras/jadlog', [], $this->auth($chave))->assertStatus(422);
});

it('exige a chave e cada cliente so mexe nas proprias transportadoras', function () {
    $this->putJson('/api/v1/transportadoras/jadlog', dadosDaJadlog())->assertStatus(401);

    [$a, $chaveA] = $this->criarCliente('B2C A');
    [, $chaveB] = $this->criarCliente('B2C B');
    $this->putJson('/api/v1/transportadoras/jadlog', dadosDaJadlog(), $this->auth($chaveA))->assertCreated();

    $this->patchJson('/api/v1/transportadoras/jadlog', ['ativa' => false], $this->auth($chaveB))->assertStatus(404);
    expect($a->credenciais()->first()->ativa)->toBeTrue();
});
