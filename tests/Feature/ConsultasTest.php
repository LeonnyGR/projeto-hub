<?php

declare(strict_types=1);

use App\Models\ChamadaTransportadora;

it('busca endereco pelo CEP no ViaCEP, sem precisar de transportadora configurada', function () {
    [$seller, $chave] = $this->criarSeller(); // nenhuma transportadora cadastrada

    $this->getJson('/api/v1/enderecos/01001-001', $this->auth($chave))
        ->assertOk()
        ->assertExactJson(['data' => [
            'cep' => '01001001', 'logradouro' => 'Praça da Sé', 'complemento' => 'lado ímpar',
            'bairro' => 'Sé', 'cidade' => 'São Paulo', 'uf' => 'SP',
        ]]);
});

it('guarda o endereco no cache: a segunda consulta nao chama o ViaCEP', function () {
    [$seller, $chave] = $this->criarSeller();

    $this->getJson('/api/v1/enderecos/01001001', $this->auth($chave))->assertOk();
    $this->getJson('/api/v1/enderecos/01001001', $this->auth($chave))->assertOk();
    $this->getJson('/api/v1/enderecos/01001-001', $this->auth($chave))->assertOk(); // mesmo CEP, com mascara

    expect(ChamadaTransportadora::where('transportadora', 'viacep')->count())->toBe(1);
});

it('CEP inexistente responde 404 e nao fica no cache', function () {
    [$seller, $chave] = $this->criarSeller();

    $this->getJson('/api/v1/enderecos/22222222', $this->auth($chave))
        ->assertStatus(404)->assertJsonPath('erro.codigo', 'cep_nao_encontrado');
    $this->getJson('/api/v1/enderecos/22222222', $this->auth($chave))->assertStatus(404);

    expect(ChamadaTransportadora::where('transportadora', 'viacep')->count())->toBe(2);
});

it('CEP com formato errado responde 422', function () {
    [$seller, $chave] = $this->criarSeller();

    $this->getJson('/api/v1/enderecos/123', $this->auth($chave))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'cep_invalido');
});

it('ViaCEP fora do ar: 502 com motivo, sem detalhes internos', function (string $cep, string $motivo) {
    [$seller, $chave] = $this->criarSeller();

    $r = $this->getJson("/api/v1/enderecos/{$cep}", $this->auth($chave))
        ->assertStatus(502)
        ->assertJsonPath('erro.codigo', 'servico_de_cep_indisponivel')
        ->assertJsonPath('erro.detalhes.motivo', $motivo);

    expect($r->getContent())->not->toContain('cURL');
})->with([
    'timeout' => ['33333333', 'sem_resposta'],
    'erro 500' => ['44444444', 'erro_na_transportadora'],
    'resposta quebrada' => ['55555555', 'resposta_invalida'],
]);

it('lista pontos de retirada de quem oferece (hoje, a Jadlog), indicando de qual transportadora e cada ponto', function () {
    [$seller, $chave] = $this->criarSellerComAsDuas();

    $r = $this->getJson($this->urlDoSeller($seller, '/pontos-retirada?cep=02031-100&tipos=LOCKER,JADLOG'), $this->auth($chave))->assertOk();

    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('data.0'))->toBe([
            'transportadora' => 'jadlog', 'id' => 'BR10598', 'nome' => 'CO JADLOG - ESTEIO 01', 'tipo_codigo' => 200,
            'logradouro' => 'RUA 24 DE AGOSTO', 'numero' => '755', 'bairro' => 'CENTRO', 'cidade' => 'ESTEIO',
            'uf' => 'RS', 'cep' => '93265160', 'latitude' => -29.851119, 'longitude' => -51.169948,
        ])
        ->and($r->json('falhas'))->toBe([]);
});

it('transportadora que nao oferece pontos de retirada (Correios) e ignorada na consulta geral', function () {
    [$seller, $chave] = $this->criarSeller();
    $this->configurarCorreios($seller);
    $this->configurarJadlog($seller);

    $r = $this->getJson($this->urlDoSeller($seller, '/pontos-retirada?cep=02031100'), $this->auth($chave))->assertOk();

    expect(collect($r->json('data'))->pluck('transportadora')->unique()->all())->toBe(['jadlog'])->and($r->json('falhas'))->toBe([]);
});

it('pedir pontos de retirada de uma transportadora que nao oferece da 422', function () {
    [$seller, $chave] = $this->criarSellerComAsDuas();

    $this->getJson($this->urlDoSeller($seller, '/pontos-retirada?cep=02031100&transportadora=correios'), $this->auth($chave))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'recurso_nao_suportado');
});

it('filtrar por uma transportadora que o B2C nao cadastrou da 422', function () {
    [$seller, $chave] = $this->criarSeller();
    $this->configurarCorreios($seller);

    $this->getJson($this->urlDoSeller($seller, '/pontos-retirada?cep=02031100&transportadora=jadlog'), $this->auth($chave))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'transportadora_nao_configurada');
});

it('sem nenhuma transportadora que ofereca pontos de retirada, responde 422', function () {
    [$seller, $chave] = $this->criarSeller(); // nada cadastrado
    $this->getJson($this->urlDoSeller($seller, '/pontos-retirada?cep=02031100'), $this->auth($chave))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'sem_pontos_de_retirada');

    $this->configurarCorreios($seller); // so Correios: tambem nao oferece
    $this->getJson($this->urlDoSeller($seller, '/pontos-retirada?cep=02031100'), $this->auth($chave))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'sem_pontos_de_retirada');
});

it('tipos que a transportadora nao conhece sao ignorados (usa todos)', function () {
    [$seller, $chave] = $this->criarSeller();
    $this->configurarJadlog($seller);
    \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
    \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['pudos' => []], 200)]);

    $this->getJson($this->urlDoSeller($seller, '/pontos-retirada?cep=02031100&tipos=QUALQUER'), $this->auth($chave))->assertOk();

    \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), 'pudos/location') && $r->data()['tipos'] === 'LOCKER,PUBLICO,JADLOG');
});

it('pontos de retirada: sem cobertura devolve lista vazia', function () {
    [$seller, $chave] = $this->criarSeller();
    $this->configurarJadlog($seller);

    $this->getJson($this->urlDoSeller($seller, '/pontos-retirada?cep=22222222'), $this->auth($chave))->assertOk()->assertExactJson(['data' => [], 'falhas' => []]);
});

it('pontos de retirada: valida cep, transportadora e tipos', function (string $consulta, string $campo) {
    [$seller, $chave] = $this->criarSeller();
    $this->configurarJadlog($seller);

    $this->getJson($this->urlDoSeller($seller, "/pontos-retirada?{$consulta}"), $this->auth($chave))
        ->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => [$campo]]]);
})->with([
    'sem cep' => ['tipos=LOCKER', 'cep'],
    'cep curto' => ['cep=123', 'cep'],
    'tipos com caracteres invalidos' => ['cep=02031100&tipos=A;B', 'tipos'],
    'transportadora desconhecida' => ['cep=02031100&transportadora=loggi', 'transportadora'],
    'campo desconhecido' => ['cep=02031100&url=http://x', 'url'],
]);

it('uma transportadora com problema nao derruba a consulta: aparece em falhas', function () {
    [$seller, $chave] = $this->criarSeller();
    $this->configurarJadlog($seller, token: 'invalido');

    $this->getJson($this->urlDoSeller($seller, '/pontos-retirada?cep=02031100'), $this->auth($chave))
        ->assertOk()->assertJsonPath('data', [])->assertJsonPath('falhas', [['transportadora' => 'jadlog', 'motivo' => 'credencial_invalida']]);
});

it('so a Jadlog declara pontos de retirada (capacidade opcional da transportadora)', function () {
    expect(new ReflectionClass(\App\Transportadoras\Jadlog::class))->toBeInstanceOf(ReflectionClass::class)
        ->and(is_subclass_of(\App\Transportadoras\Jadlog::class, \App\Transportadoras\PontosDeRetirada::class))->toBeTrue()
        ->and(is_subclass_of(\App\Transportadoras\Correios::class, \App\Transportadoras\PontosDeRetirada::class))->toBeFalse();
});

it('GUARDA: nenhum codigo fora de app/Transportadoras cita Jadlog ou Correios (adicionar transportadora nao pode exigir mexer ali)', function () {
    $proibidos = [];

    foreach (['Services', 'Http', 'Models', 'Console', 'Support', 'Enums', 'Rules', 'Exceptions', 'Providers'] as $pasta) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path("app/{$pasta}"))) as $arquivo) {
            if ($arquivo->getExtension() !== 'php') {
                continue;
            }

            // Olha so o codigo (textos entre aspas e nomes de classe), nao comentarios.
            foreach (token_get_all((string) file_get_contents($arquivo->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    && preg_match('/jadlog|correios/i', $token[1])) {
                    $proibidos[] = str_replace(base_path().'/', '', $arquivo->getPathname()).': '.$token[1];
                }
            }
        }
    }

    expect($proibidos)->toBe([]);
});

it('as consultas exigem autenticacao', function () {
    [$seller] = $this->criarSeller();
    $this->getJson('/api/v1/enderecos/01001001')->assertStatus(401);
    $this->getJson($this->urlDoSeller($seller, '/pontos-retirada?cep=02031100'))->assertStatus(401);
});

it('por padrao o ViaCEP nao e simulado: a chamada segue para a internet de verdade', function () {
    $requisicao = new \Illuminate\Http\Client\Request(new \GuzzleHttp\Psr7\Request('GET', 'https://viacep.com.br/ws/35432065/json/'));

    config(['transportadoras.viacep.modo' => 'real']);
    expect(\App\Transportadoras\Mock\SimuladorHttp::responder($requisicao))->toBeNull(); // null = nao simula

    config(['transportadoras.viacep.modo' => 'mock']);
    expect(\App\Transportadoras\Mock\SimuladorHttp::responder($requisicao))->not->toBeNull();
});
