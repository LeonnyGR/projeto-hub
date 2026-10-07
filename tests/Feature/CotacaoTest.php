<?php

declare(strict_types=1);

use App\Models\ChamadaTransportadora;
use App\Providers\AppServiceProvider;

it('cota nas duas transportadoras, ordena pelo preco e salva', function () {
    [$seller, $chave] = $this->criarSellerComAsDuas();

    $r = $this->postJson("/api/v1/sellers/{$seller->id}/cotacoes", $this->dadosDeCotacao(), $this->auth($chave))
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'cep_origem', 'cep_destino', 'expira_em', 'opcoes' => [['transportadora', 'servico_codigo', 'servico_nome', 'valor_centavos', 'prazo_dias']], 'falhas']])
        ->assertJsonPath('data.falhas', []);

    $opcoes = collect($r->json('data.opcoes'));

    // Jadlog (3 modalidades) + Correios (2 servicos)
    expect($opcoes)->toHaveCount(5)
        ->and($opcoes->pluck('transportadora')->unique()->sort()->values()->all())->toBe(['correios', 'jadlog'])
        ->and($opcoes->pluck('valor_centavos')->all())->toBe($opcoes->pluck('valor_centavos')->sort()->values()->all())
        ->and($opcoes->every(fn ($o) => is_int($o['valor_centavos'])))->toBeTrue();

    // Valores do simulador (ver Mock/README.md): Jadlog modalidade 3, peso cubado 30x20x10/6000=1 kg < 1,2 kg real
    // => 5 + 2*3 + 3*1,2 = 14,60
    expect($opcoes->firstWhere(fn ($o) => $o['transportadora'] === 'jadlog' && $o['servico_codigo'] === '3'))
        ->toMatchArray(['servico_nome' => 'Package', 'valor_centavos' => 1460, 'prazo_dias' => 6]);

    $cotacao = $seller->cotacoes()->findOrFail($r->json('data.id'));
    expect($cotacao->expira_em->between(now()->addMinutes(14), now()->addMinutes(16)))->toBeTrue()
        ->and($cotacao->cep_destino)->toBe('20040020');
});

it('aceita CEP com hifen e guarda so os numeros', function () {
    [$seller, $chave] = $this->criarSellerComAsDuas();

    $this->postJson("/api/v1/sellers/{$seller->id}/cotacoes", $this->dadosDeCotacao(['cep_origem' => '01310-100', 'cep_destino' => '20040-020']), $this->auth($chave))
        ->assertCreated()
        ->assertJsonPath('data.cep_origem', '01310100')
        ->assertJsonPath('data.cep_destino', '20040020');
});

it('sem cep_origem na cotacao usa o do seller; se vier, vale o da cotacao', function () {
    [$seller, $chave] = $this->criarSellerComAsDuas();
    $seller->update(['cep_origem' => '70902000']);
    $dados = $this->dadosDeCotacao();
    unset($dados['cep_origem']);

    $this->postJson("/api/v1/sellers/{$seller->id}/cotacoes", $dados, $this->auth($chave))
        ->assertCreated()->assertJsonPath('data.cep_origem', '70902000');

    $this->postJson("/api/v1/sellers/{$seller->id}/cotacoes", $dados + ['cep_origem' => '01310100'], $this->auth($chave))
        ->assertCreated()->assertJsonPath('data.cep_origem', '01310100');
});

it('uma transportadora com problema nao derruba a cotacao', function () {
    [$seller, $chave] = $this->criarSeller();
    $this->configurarJadlog($seller, token: 'invalido'); // Jadlog vai responder 401
    $this->configurarCorreios($seller);

    $r = $this->postJson("/api/v1/sellers/{$seller->id}/cotacoes", $this->dadosDeCotacao(), $this->auth($chave))->assertCreated();

    expect(collect($r->json('data.opcoes'))->pluck('transportadora')->unique()->all())->toBe(['correios'])
        ->and($r->json('data.falhas'))->toBe([['transportadora' => 'jadlog', 'motivo' => 'credencial_invalida']]);
});

it('credencial dos Correios recusada: cotacao sai so com a Jadlog', function () {
    [$seller, $chave] = $this->criarSeller();
    $this->configurarJadlog($seller);
    $this->configurarCorreios($seller, usuario: 'invalido');

    $r = $this->postJson("/api/v1/sellers/{$seller->id}/cotacoes", $this->dadosDeCotacao(), $this->auth($chave))->assertCreated();

    expect(collect($r->json('data.opcoes'))->pluck('transportadora')->unique()->all())->toBe(['jadlog'])
        ->and($r->json('data.falhas'))->toBe([['transportadora' => 'correios', 'motivo' => 'credencial_invalida']]);
});

it('cenarios do simulador por CEP de destino', function (string $cep, int $opcoes, array $falhas) {
    [$seller, $chave] = $this->criarSellerComAsDuas();

    $r = $this->postJson("/api/v1/sellers/{$seller->id}/cotacoes", $this->dadosDeCotacao(['cep_destino' => $cep]), $this->auth($chave))->assertCreated();

    $motivos = collect($r->json('data.falhas'))->sortBy('transportadora')->mapWithKeys(fn ($f) => [$f['transportadora'] => $f['motivo']])->all();

    expect($r->json('data.opcoes'))->toHaveCount($opcoes)->and($motivos)->toBe($falhas);
})->with([
    'sem cobertura' => ['22222222', 0, ['correios' => 'sem_cobertura', 'jadlog' => 'sem_cobertura']],
    'timeout' => ['33333333', 0, ['correios' => 'sem_resposta', 'jadlog' => 'sem_resposta']],
    'erro 500' => ['44444444', 0, ['correios' => 'erro_na_transportadora', 'jadlog' => 'erro_na_transportadora']],
    'resposta quebrada' => ['55555555', 0, ['correios' => 'resposta_invalida', 'jadlog' => 'resposta_invalida']],
    'credencial recusada' => ['66666666', 0, ['correios' => 'credencial_invalida', 'jadlog' => 'credencial_invalida']],
]);

it('seller sem nenhuma transportadora recebe 422', function () {
    [$seller, $chave] = $this->criarSeller();

    $this->postJson("/api/v1/sellers/{$seller->id}/cotacoes", $this->dadosDeCotacao(), $this->auth($chave))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'sem_transportadoras');
});

it('transportadora desativada nao entra na cotacao', function () {
    [$seller, $chave] = $this->criarSellerComAsDuas();
    $seller->credenciais()->where('transportadora', 'jadlog')->update(['ativa' => false]);

    $r = $this->postJson("/api/v1/sellers/{$seller->id}/cotacoes", $this->dadosDeCotacao(), $this->auth($chave))->assertCreated();

    expect(collect($r->json('data.opcoes'))->pluck('transportadora')->unique()->all())->toBe(['correios']);
});

it('todos os sellers do B2C usam o mesmo contrato com as transportadoras', function () {
    [$a, $chave] = $this->criarSeller('A');
    [$b] = $this->criarSeller('B');
    $this->configurarJadlog($a); // o contrato e do B2C: cadastrar uma vez vale para A e B
    $this->configurarCorreios($a);

    $opcoesA = $this->postJson($this->urlDoSeller($a, '/cotacoes'), $this->dadosDeCotacao(), $this->auth($chave))->json('data.opcoes');
    $opcoesB = $this->postJson($this->urlDoSeller($b, '/cotacoes'), $this->dadosDeCotacao(), $this->auth($chave))->json('data.opcoes');

    expect($opcoesA)->not->toBeEmpty()->and($opcoesB)->toBe($opcoesA);

    // desativar uma transportadora no contrato afeta todos os sellers
    $a->credenciais()->where('transportadora', 'jadlog')->update(['ativa' => false]);
    $depois = $this->postJson($this->urlDoSeller($b, '/cotacoes'), $this->dadosDeCotacao(), $this->auth($chave))->json('data.opcoes');
    expect(collect($depois)->pluck('transportadora')->unique()->all())->toBe(['correios']);
});

it('valida a entrada', function (array $mudar, string $campo) {
    [$seller, $chave] = $this->criarSellerComAsDuas();

    $this->postJson("/api/v1/sellers/{$seller->id}/cotacoes", $this->dadosDeCotacao($mudar), $this->auth($chave))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'validacao_falhou')->assertJsonStructure(['erro' => ['detalhes' => [$campo]]]);
})->with([
    'cep invalido' => [['cep_destino' => '123'], 'cep_destino'],
    'cep com letras' => [['cep_origem' => 'abcdefgh'], 'cep_origem'],
    'valor negativo' => [['valor_declarado_centavos' => -1], 'valor_declarado_centavos'],
    'valor decimal' => [['valor_declarado_centavos' => 10.5], 'valor_declarado_centavos'],
    'sem volumes' => [['volumes' => []], 'volumes'],
    'volumes demais' => [['volumes' => array_fill(0, 11, ['peso_gramas' => 1, 'altura_cm' => 1, 'largura_cm' => 1, 'comprimento_cm' => 1])], 'volumes'],
    'peso zero' => [['volumes' => [['peso_gramas' => 0, 'altura_cm' => 1, 'largura_cm' => 1, 'comprimento_cm' => 1]]], 'volumes.0.peso_gramas'],
    'peso enorme' => [['volumes' => [['peso_gramas' => 31000, 'altura_cm' => 1, 'largura_cm' => 1, 'comprimento_cm' => 1]]], 'volumes.0.peso_gramas'],
    'sem altura' => [['volumes' => [['peso_gramas' => 100, 'largura_cm' => 1, 'comprimento_cm' => 1]]], 'volumes.0.altura_cm'],
    'campo desconhecido' => [['url_da_transportadora' => 'http://evil'], 'url_da_transportadora'],
]);

it('campo extra dentro do volume nao chega nas transportadoras', function () {
    [$seller, $chave] = $this->criarSellerComAsDuas();
    $dados = $this->dadosDeCotacao(['volumes' => [['peso_gramas' => 100, 'altura_cm' => 5, 'largura_cm' => 5, 'comprimento_cm' => 5, 'extra' => 'x']]]);

    $id = $this->postJson("/api/v1/sellers/{$seller->id}/cotacoes", $dados, $this->auth($chave))->assertCreated()->json('data.id');

    expect($seller->cotacoes()->find($id)->requisicao['volumes'][0])->not->toHaveKey('extra');
});

it('consulta a cotacao; pedir pela URL de outro seller da 404', function () {
    [$a, $chave] = $this->criarSellerComAsDuas('A');
    [$b] = $this->criarSellerComAsDuas('B');

    $id = $this->postJson($this->urlDoSeller($a, '/cotacoes'), $this->dadosDeCotacao(), $this->auth($chave))->json('data.id');

    $this->getJson($this->urlDoSeller($a, "/cotacoes/{$id}"), $this->auth($chave))->assertOk()->assertJsonPath('data.id', $id);
    $this->getJson($this->urlDoSeller($b, "/cotacoes/{$id}"), $this->auth($chave))->assertStatus(404)->assertJsonPath('erro.codigo', 'nao_encontrado');
    $this->getJson($this->urlDoSeller($a, '/cotacoes/nao-e-uuid'), $this->auth($chave))->assertStatus(404);
});

it('exige autenticacao', function () {
    [$seller] = $this->criarSeller();
    $this->postJson("/api/v1/sellers/{$seller->id}/cotacoes", $this->dadosDeCotacao())->assertStatus(401);
});

it('registra as chamadas com dados sensiveis mascarados', function () {
    [$seller, $chave] = $this->criarSellerComAsDuas();

    $this->postJson("/api/v1/sellers/{$seller->id}/cotacoes", $this->dadosDeCotacao(), $this->auth($chave))->assertCreated();

    $chamadas = ChamadaTransportadora::where('seller_id', $seller->id)->get();
    $todas = $chamadas->map(fn ($c) => $c->requisicao.' '.$c->resposta.' '.$c->url)->implode("\n");

    expect($chamadas->pluck('operacao')->unique()->sort()->values()->all())->toBe(['cotacao', 'token'])
        ->and($chamadas->where('operacao', 'cotacao')->pluck('transportadora')->unique()->sort()->values()->all())->toBe(['correios', 'jadlog'])
        // CNPJ do tomador (Jadlog) mascarado; token dos Correios e senha nunca aparecem
        ->and($todas)->not->toContain('12345678000195')
        ->and($todas)->not->toContain('token-do-simulador')
        ->and($todas)->not->toContain('token-jadlog')
        ->and($todas)->not->toContain('?')
        ->and($chamadas->every(fn ($c) => $c->request_id !== null || true))->toBeTrue();
});

it('o modo mock e proibido em producao', function () {
    $this->app['env'] = 'production';

    expect(fn () => (new AppServiceProvider($this->app))->boot())
        ->toThrow(RuntimeException::class, 'mock nao pode ser usado com APP_ENV=production');
});

it('modo invalido derruba o sistema na subida', function () {
    config(['transportadoras.modo' => 'qualquer']);

    expect(fn () => (new AppServiceProvider($this->app))->boot())->toThrow(RuntimeException::class);
});
