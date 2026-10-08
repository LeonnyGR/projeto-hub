<?php

declare(strict_types=1);

it('limita por cliente e responde 429 com Retry-After', function () {
    config(['frete.limites.cotacoes' => 2]);
    [, $credenciaisA] = $this->criarCliente('A');
    [, $credenciaisB] = $this->criarCliente('B');

    $this->getJson('/api/v1/_teste/eu', $this->auth($credenciaisA))->assertOk();
    $this->getJson('/api/v1/_teste/eu', $this->auth($credenciaisA))->assertOk();

    $r = $this->getJson('/api/v1/_teste/eu', $this->auth($credenciaisA));
    $r->assertStatus(429)->assertJsonPath('erro.codigo', 'muitas_requisicoes')->assertJsonStructure(['request_id']);
    expect((int) $r->headers->get('Retry-After'))->toBeBetween(1, 60);

    // O contador e de cada cliente: um cliente muito movimentado nao atrapalha o outro.
    $this->getJson('/api/v1/_teste/eu', $this->auth($credenciaisB))->assertOk();
});
