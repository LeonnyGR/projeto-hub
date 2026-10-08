<?php

declare(strict_types=1);

use App\Enums\PapelUsuario;
use App\Transportadoras\Correios;
use App\Transportadoras\Jadlog;

// Catalogo de campos das transportadoras (para montar o formulario) e mascara das credenciais nas respostas.

function cadastroDeExemploJadlog(array $mudar = []): array
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
            'servicos' => [['codigo' => '3', 'nome' => 'Package']],
        ],
    ], $mudar);
}

function cadastroDeExemploCorreios(): array
{
    return [
        'credenciais' => ['usuario' => 'usuario-correios', 'senha' => 'senha-secreta', 'cartao_postagem' => '0012345678'],
        'configuracao' => ['contrato' => '9912345678', 'servicos' => [['codigo' => '03220', 'nome' => 'SEDEX']]],
    ];
}

/** Faz a chamada pela porta pedida: 'chave' (chave de API) ou 'portal' (dono logado). Devolve a resposta. */
function chamarPor($teste, string $porta, string $metodo, string $caminho, array $corpo = []): \Illuminate\Testing\TestResponse
{
    if ($porta === 'chave') {
        [, $chave] = $teste->criarCliente();

        $cabecalhos = $teste->auth($chave);

        return $metodo === 'get'
            ? $teste->getJson('/api/v1/'.$caminho, $cabecalhos)
            : $teste->{$metodo.'Json'}('/api/v1/'.$caminho, $corpo, $cabecalhos);
    }

    [$cliente] = $teste->criarCliente();
    $teste->actingAs($teste->criarUsuario($cliente, PapelUsuario::AdmTenant), 'portal');

    return $metodo === 'get'
        ? $teste->getJson('/api/v1/portal/'.$caminho)
        : $teste->{$metodo.'Json'}('/api/v1/portal/'.$caminho, $corpo);
}

/** Procura um campo no catalogo devolvido pela API. */
function campoDoCatalogo(array $catalogo, string $transportadora, string $campo): array
{
    $t = collect($catalogo)->firstWhere('codigo', $transportadora);

    return collect($t['campos'])->firstWhere('campo', $campo);
}

// ---------------------------------------------------------------- catalogo

it('lista Jadlog e Correios com nome e campos', function (string $porta) {
    $r = chamarPor($this, $porta, 'get', 'transportadoras/catalogo')->assertOk();

    expect(collect($r->json('data'))->pluck('codigo')->all())->toBe(['jadlog', 'correios'])
        ->and(collect($r->json('data'))->pluck('nome')->all())->toBe(['Jadlog', 'Correios']);

    foreach ($r->json('data') as $t) {
        expect($t['campos'])->not->toBeEmpty();
        foreach ($t['campos'] as $campo) {
            expect($campo)->toHaveKeys(['campo', 'grupo', 'titulo', 'tipo', 'obrigatorio', 'padrao', 'ajuda', 'opcoes']);
        }
    }
})->with(['chave de API' => ['chave'], 'portal' => ['portal']]);

it('campos secretos vem com tipo secreto e grupo credenciais', function (string $porta) {
    $catalogo = chamarPor($this, $porta, 'get', 'transportadoras/catalogo')->json('data');

    foreach ([['jadlog', 'credenciais.token'], ['correios', 'credenciais.senha'], ['correios', 'credenciais.cartao_postagem']] as [$t, $campo]) {
        $c = campoDoCatalogo($catalogo, $t, $campo);
        expect($c['tipo'])->toBe('secreto')->and($c['grupo'])->toBe('credenciais');
    }

    expect(campoDoCatalogo($catalogo, 'correios', 'credenciais.usuario')['tipo'])->toBe('texto')
        ->and(campoDoCatalogo($catalogo, 'jadlog', 'configuracao.cod_cliente')['grupo'])->toBe('configuracao');
})->with(['chave de API' => ['chave'], 'portal' => ['portal']]);

it('o titulo nao traz dicas do terminal', function (string $porta) {
    $catalogo = chamarPor($this, $porta, 'get', 'transportadoras/catalogo')->json('data');

    foreach ($catalogo as $t) {
        foreach ($t['campos'] as $campo) {
            expect($campo['titulo'])->not->toContain('(Enter');
        }
    }

    expect(campoDoCatalogo($catalogo, 'jadlog', 'configuracao.tomador.email')['titulo'])->toBe('Tomador: e-mail');
})->with(['chave de API' => ['chave'], 'portal' => ['portal']]);

it('tipo_coleta da Jadlog e select com opcoes K e S e padrao K; servicos tem tipo servicos', function (string $porta) {
    $catalogo = chamarPor($this, $porta, 'get', 'transportadoras/catalogo')->json('data');

    $coleta = campoDoCatalogo($catalogo, 'jadlog', 'configuracao.tipo_coleta');
    expect($coleta['tipo'])->toBe('select')
        ->and(array_keys($coleta['opcoes']))->toBe(['K', 'S'])
        ->and($coleta['padrao'])->toBe('K')
        ->and($coleta['obrigatorio'])->toBeFalse();

    expect(campoDoCatalogo($catalogo, 'jadlog', 'configuracao.servicos')['tipo'])->toBe('servicos')
        ->and(campoDoCatalogo($catalogo, 'correios', 'configuracao.servicos')['tipo'])->toBe('servicos');
})->with(['chave de API' => ['chave'], 'portal' => ['portal']]);

it('o operador tambem ve o catalogo no portal', function () {
    [$cliente] = $this->criarCliente();
    $this->actingAs($this->criarUsuario($cliente, PapelUsuario::OperadorTenant), 'portal');

    $this->getJson('/api/v1/portal/transportadoras/catalogo')->assertOk()->assertJsonCount(2, 'data');
});

it('o catalogo exige autenticacao', function () {
    $this->getJson('/api/v1/transportadoras/catalogo')->assertUnauthorized();
    $this->getJson('/api/v1/portal/transportadoras/catalogo')->assertUnauthorized();
});

it('catalogo e regras andam juntos: obrigatorio bate com required e todo campo tem regra', function (string $porta) {
    $catalogo = chamarPor($this, $porta, 'get', 'transportadoras/catalogo')->json('data');
    $classes = ['jadlog' => Jadlog::class, 'correios' => Correios::class];

    foreach ($catalogo as $t) {
        $regras = $classes[$t['codigo']]::regras();

        foreach ($t['campos'] as $campo) {
            expect($regras)->toHaveKey($campo['campo']);
            expect($campo['obrigatorio'])->toBe(in_array('required', $regras[$campo['campo']], true), $campo['campo']);
        }
    }
})->with(['chave de API' => ['chave'], 'portal' => ['portal']]);

it('um cadastro montado so com os campos obrigatorios do catalogo valida', function (string $porta, string $transportadora) {
    $exemplos = [
        'jadlog' => cadastroDeExemploJadlog(),
        'correios' => cadastroDeExemploCorreios(),
    ];

    $catalogo = chamarPor($this, $porta, 'get', 'transportadoras/catalogo')->json('data');
    $campos = collect(collect($catalogo)->firstWhere('codigo', $transportadora)['campos'])->where('obrigatorio', true);

    // Monta o corpo copiando do exemplo valido so os campos obrigatorios.
    $corpo = [];
    foreach ($campos as $campo) {
        $valor = data_get($exemplos[$transportadora], $campo['campo']);
        expect($valor)->not->toBeNull($campo['campo']);
        data_set($corpo, $campo['campo'], $valor);
    }

    [$cliente, $chave] = $this->criarCliente();
    if ($porta === 'chave') {
        $this->putJson("/api/v1/transportadoras/{$transportadora}", $corpo, $this->auth($chave))->assertCreated();
    } else {
        $this->actingAs($this->criarUsuario($cliente, PapelUsuario::AdmTenant), 'portal');
        $this->putJson("/api/v1/portal/transportadoras/{$transportadora}", $corpo)->assertCreated();
    }
})->with(['chave de API' => ['chave'], 'portal' => ['portal']])->with(['jadlog', 'correios']);

// ---------------------------------------------------------------- mascara

it('GET e PUT trazem credenciais_mascaradas: token longo mostra o final, curtos ficam totalmente mascarados', function (string $porta) {
    $segredos = ['token-longo-da-jadlog-abcd1234', 'usuario-correios', 'senha-curta', '0012345678'];
    $bolinhas = str_repeat('•', 8);

    [$cliente, $chave] = $this->criarCliente();
    $base = '/api/v1/';
    $cabecalhos = $this->auth($chave);
    if ($porta === 'portal') {
        $this->actingAs($this->criarUsuario($cliente, PapelUsuario::AdmTenant), 'portal');
        $base = '/api/v1/portal/';
        $cabecalhos = [];
    }

    $jadlog = cadastroDeExemploJadlog(['credenciais' => ['token' => $segredos[0]]]);
    $correios = cadastroDeExemploCorreios();
    $correios['credenciais'] = ['usuario' => $segredos[1], 'senha' => $segredos[2], 'cartao_postagem' => $segredos[3]];

    $put = $this->putJson($base.'transportadoras/jadlog', $jadlog, $cabecalhos)->assertCreated();
    $put->assertJsonPath('data.credenciais_mascaradas.token', str_repeat('•', 6).'1234');
    $corpos = [$put->getContent()];

    $corpos[] = $this->putJson($base.'transportadoras/correios', $correios, $cabecalhos)->assertCreated()
        ->assertJsonPath('data.credenciais_mascaradas.usuario', str_repeat('•', 6).'eios')
        ->assertJsonPath('data.credenciais_mascaradas.senha', $bolinhas)
        ->assertJsonPath('data.credenciais_mascaradas.cartao_postagem', $bolinhas)->getContent();

    $corpos[] = $this->patchJson($base.'transportadoras/correios', ['ativa' => false], $cabecalhos)->assertOk()
        ->assertJsonPath('data.credenciais_mascaradas.senha', $bolinhas)->getContent();

    $lista = $this->getJson($base.'transportadoras', $cabecalhos)->assertOk();
    expect(collect($lista->json('data'))->firstWhere('transportadora', 'jadlog')['credenciais_mascaradas']['token'])->toBe(str_repeat('•', 6).'1234');
    $corpos[] = $lista->getContent();

    foreach ($corpos as $corpo) {
        foreach (['token-longo-da-jadlog-abcd1234', 'senha-curta', '0012345678', 'usuario-correios'] as $segredo) {
            expect($corpo)->not->toContain($segredo);
        }
    }
})->with(['chave de API' => ['chave'], 'portal' => ['portal']]);
