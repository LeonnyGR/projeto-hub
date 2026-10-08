<?php

declare(strict_types=1);

use App\Models\CredencialTransportadora;
use Illuminate\Support\Facades\DB;

// As credenciais das transportadoras so entram no sistema por comando (nao por API).

/** Responde as perguntas do comando para a Jadlog. $mudar troca respostas pelo rotulo. */
function responderJadlog($comando, array $mudar = [])
{
    $respostas = [
        'Token da Jadlog' => 'token-secreto-da-jadlog',
        'Tomador do frete (titular do contrato): nome ou razao social' => 'Bartofil Comercio Ltda',
        'Tomador: CNPJ' => '12.345.678/0001-95',
        'Tomador: telefone com DDD' => '(11) 3333-4444',
        'Tomador: e-mail (Enter para pular)' => 'frete@bartofil.com.br',
        'Tomador: CEP' => '01001-000',
        'Tomador: rua' => 'Praca da Se',
        'Tomador: numero' => '1',
        'Tomador: complemento (Enter para pular)' => '',
        'Tomador: bairro' => 'Se',
        'Tomador: cidade' => 'Sao Paulo',
        'Tomador: UF' => 'sp',
        'Codigo do cliente na Jadlog' => '000123',
        'Conta corrente Jadlog (Enter se nao for correntista)' => '',
        'Numero do contrato (Enter se nao tiver)' => '',
        'Codigo da unidade (franquia) emissora (Enter se nao souber)' => '',
        'Tipo de coleta: K = Jadlog coleta no lojista, S = o lojista leva ate a Jadlog' => 'K',
        'Modalidades contratadas, no formato codigo:nome,codigo:nome (ex.: 3:Package,0:Expresso)' => '3:Package, 0:Expresso',
    ];

    foreach (array_merge($respostas, $mudar) as $pergunta => $resposta) {
        $comando->expectsQuestion($pergunta, $resposta);
    }

    return $comando;
}

function responderCorreios($comando)
{
    foreach ([
        'Usuario do Meu Correios' => 'usuario-correios',
        'Senha/codigo de acesso a API dos Correios' => 'senha-secreta',
        'Numero do cartao de postagem' => '0012345678',
        'Numero do contrato (Enter se nao tiver)' => '9912345678',
        'Servicos contratados, no formato codigo:nome,codigo:nome (ex.: 03220:SEDEX,04669:PAC)' => '03220:SEDEX,04669:PAC',
    ] as $pergunta => $resposta) {
        $comando->expectsQuestion($pergunta, $resposta);
    }

    return $comando;
}

it('cadastra a Jadlog: dados normalizados, segredo criptografado', function () {
    [$cliente] = $this->criarCliente('B2C');

    responderJadlog($this->artisan('transportadora:configurar', ['transportadora' => 'jadlog']))->assertSuccessful();

    $cred = $cliente->credenciais()->where('transportadora', 'jadlog')->firstOrFail();

    expect($cred->credenciais)->toBe(['token' => 'token-secreto-da-jadlog'])
        ->and($cred->ativa)->toBeTrue()
        ->and($cred->configuracao['tomador']['documento'])->toBe('12345678000195')
        ->and($cred->configuracao['tomador']['telefone'])->toBe('1133334444')
        ->and($cred->configuracao['tomador']['endereco']['cep'])->toBe('01001000')
        ->and($cred->configuracao['tomador']['endereco']['uf'])->toBe('SP')
        ->and($cred->configuracao['tipo_coleta'])->toBe('K')
        ->and($cred->configuracao['servicos'])->toEqual([['codigo' => '3', 'nome' => 'Package'], ['codigo' => '0', 'nome' => 'Expresso']]);

    $bruto = DB::table('credenciais_transportadoras')->where('id', $cred->id)->value('credenciais');
    expect($bruto)->not->toContain('token-secreto-da-jadlog');
});

it('cadastra os Correios', function () {
    [$cliente] = $this->criarCliente('B2C');

    responderCorreios($this->artisan('transportadora:configurar', ['transportadora' => 'correios']))->assertSuccessful();

    $cred = $cliente->credenciais()->where('transportadora', 'correios')->firstOrFail();
    expect($cred->credenciais)->toBe(['usuario' => 'usuario-correios', 'senha' => 'senha-secreta', 'cartao_postagem' => '0012345678'])
        ->and($cred->configuracao['servicos'][0])->toEqual(['codigo' => '03220', 'nome' => 'SEDEX']);
});

it('o que foi cadastrado vale para os clientes e aparece na consulta da API sem segredo', function () {
    [$cliente, $chave] = $this->criarCliente('B2C');
    responderJadlog($this->artisan('transportadora:configurar', ['transportadora' => 'jadlog']))->assertSuccessful();

    $this->postJson('/api/v1/cotacoes', $this->dadosDeCotacao(), $this->auth($chave))
        ->assertCreated()->assertJsonPath('data.falhas', []);

    $r = $this->getJson('/api/v1/transportadoras', $this->auth($chave))->assertOk();
    expect($r->json('data.0.transportadora'))->toBe('jadlog')->and($r->getContent())->not->toContain('token-secreto-da-jadlog');
});

it('recusa dados invalidos e nao salva nada', function () {
    [$cliente] = $this->criarCliente('B2C');

    responderJadlog($this->artisan('transportadora:configurar', ['transportadora' => 'jadlog']), [
        'Tomador: CNPJ' => '11222333000182', // digito verificador errado
        'Tomador: UF' => 'XX',
        'Tipo de coleta: K = Jadlog coleta no lojista, S = o lojista leva ate a Jadlog' => 'Z',
    ])->assertFailed();

    expect($cliente->credenciais()->count())->toBe(0);
});

it('campos obrigatorios nao podem ficar em branco', function () {
    [$cliente] = $this->criarCliente('B2C');

    responderJadlog($this->artisan('transportadora:configurar', ['transportadora' => 'jadlog']), ['Token da Jadlog' => '', 'Codigo do cliente na Jadlog' => ''])->assertFailed();

    expect($cliente->credenciais()->count())->toBe(0);
});

it('pede confirmacao antes de substituir credenciais que ja existem', function () {
    [$cliente] = $this->criarCliente('B2C');
    responderJadlog($this->artisan('transportadora:configurar', ['transportadora' => 'jadlog']))->assertSuccessful();

    // Responde "nao": nada muda e nenhuma pergunta de dados e feita
    $this->artisan('transportadora:configurar', ['transportadora' => 'jadlog'])
        ->expectsConfirmation("O cliente 'B2C' ja tem 'jadlog' cadastrada. Substituir tudo?", 'no')
        ->assertSuccessful();
    expect($cliente->credenciais()->first()->credenciais['token'])->toBe('token-secreto-da-jadlog');

    // Responde "sim": substitui
    responderJadlog(
        $this->artisan('transportadora:configurar', ['transportadora' => 'jadlog'])
            ->expectsConfirmation("O cliente 'B2C' ja tem 'jadlog' cadastrada. Substituir tudo?", 'yes'),
        ['Token da Jadlog' => 'token-novo'],
    )->assertSuccessful();

    expect($cliente->credenciais()->count())->toBe(1)->and($cliente->credenciais()->first()->credenciais['token'])->toBe('token-novo');
});

it('recusa transportadora desconhecida e cliente inexistente', function () {
    [$cliente] = $this->criarCliente('B2C');

    $this->artisan('transportadora:configurar', ['transportadora' => 'loggi'])->assertFailed();
    $this->artisan('transportadora:configurar', ['transportadora' => 'jadlog', 'cliente' => '00000000-0000-0000-0000-000000000000'])->assertFailed();
});

it('com mais de um cliente, o id e obrigatorio', function () {
    [$a] = $this->criarCliente('B2C A');
    $this->criarCliente('B2C B');

    $this->artisan('transportadora:configurar', ['transportadora' => 'correios'])->assertFailed();

    responderCorreios($this->artisan('transportadora:configurar', ['transportadora' => 'correios', 'cliente' => $a->id]))->assertSuccessful();
    expect(CredencialTransportadora::count())->toBe(1)->and($a->credenciais()->count())->toBe(1);
});

it('ativar e desativar uma transportadora sem redigitar as credenciais', function () {
    [$cliente, $chave] = $this->criarCliente('B2C');
    $this->configurarJadlog($cliente);
    $this->configurarCorreios($cliente);

    $this->artisan('transportadora:ativar', ['transportadora' => 'jadlog', '--desativar' => true])->assertSuccessful();

    $r = $this->postJson('/api/v1/cotacoes', $this->dadosDeCotacao(), $this->auth($chave))->assertCreated();
    expect(collect($r->json('data.opcoes'))->pluck('transportadora')->unique()->all())->toBe(['correios']);
    expect($cliente->credenciais()->where('transportadora', 'jadlog')->first()->credenciais['token'])->toBe('token-jadlog'); // credenciais intactas

    $this->artisan('transportadora:ativar', ['transportadora' => 'jadlog'])->assertSuccessful();
    $r = $this->postJson('/api/v1/cotacoes', $this->dadosDeCotacao(), $this->auth($chave))->assertCreated();
    expect(collect($r->json('data.opcoes'))->pluck('transportadora')->unique()->sort()->values()->all())->toBe(['correios', 'jadlog']);
});

it('nao ativa transportadora que nao foi cadastrada', function () {
    $this->criarCliente('B2C');

    $this->artisan('transportadora:ativar', ['transportadora' => 'jadlog'])->assertFailed();
});
