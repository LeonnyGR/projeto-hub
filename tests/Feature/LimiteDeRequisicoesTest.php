<?php

declare(strict_types=1);

it('limita por seller e responde 429 com Retry-After', function () {
    config(['frete.limites.cotacoes' => 2]);
    [$a, $chave] = $this->criarSeller('A');
    [$b] = $this->criarSeller('B');

    $this->getJson($this->urlDoSeller($a, '/_teste/eu'), $this->auth($chave))->assertOk();
    $this->getJson($this->urlDoSeller($a, '/_teste/eu'), $this->auth($chave))->assertOk();

    $r = $this->getJson($this->urlDoSeller($a, '/_teste/eu'), $this->auth($chave));
    $r->assertStatus(429)->assertJsonPath('erro.codigo', 'muitas_requisicoes')->assertJsonStructure(['request_id']);
    expect((int) $r->headers->get('Retry-After'))->toBeBetween(1, 60);

    // O contador e de cada seller: um lojista muito movimentado nao atrapalha o outro (mesmo com a mesma chave).
    $this->getJson($this->urlDoSeller($b, '/_teste/eu'), $this->auth($chave))->assertOk();
});

it('rotas sem seller contam por cliente da API', function () {
    config(['frete.limites.cotacoes' => 1]);
    [, $chave] = $this->criarCliente();

    $this->getJson('/api/v1/_teste/eu', $this->auth($chave))->assertOk();
    $this->getJson('/api/v1/_teste/eu', $this->auth($chave))->assertStatus(429);
});
