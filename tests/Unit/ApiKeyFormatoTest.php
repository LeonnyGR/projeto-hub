<?php

declare(strict_types=1);

use App\Services\ApiKeyService;

it('gera chave no formato prefixo.segredo com pelo menos 32 bytes de segredo', function () {
    $k = ApiKeyService::gerar();

    expect($k['chave'])->toBe($k['prefixo'].'.'.$k['segredo'])
        ->and($k['prefixo'])->toMatch('/^fk_[a-f0-9]{12}$/')
        ->and(strlen($k['segredo']))->toBeGreaterThanOrEqual(43) // 32 bytes em base64 sem padding
        ->and($k['segredo'])->toMatch('/^[A-Za-z0-9_-]+$/');
});

it('gera chaves diferentes a cada chamada', function () {
    $segredos = array_map(fn () => ApiKeyService::gerar()['segredo'], range(1, 50));

    expect(array_unique($segredos))->toHaveCount(50);
});

it('o hash e o sha256 do segredo e nao contem o segredo', function () {
    $k = ApiKeyService::gerar();
    $hash = ApiKeyService::hash($k['segredo']);

    expect($hash)->toHaveLength(64)->toBe(hash('sha256', $k['segredo']))->not->toContain($k['segredo']);
});

it('rejeita chaves com formato invalido', function (string $entrada) {
    expect(ApiKeyService::separar($entrada))->toBe([null, null]);
})->with([
    'vazia' => [''],
    'sem ponto' => ['fk_abc123abc123'],
    'sem segredo' => ['fk_abc123abc123.'],
    'sem prefixo' => ['.segredo'],
    'marca errada' => ['xx_abc123abc123.segredo'],
    'enorme' => [str_repeat('a', 500)],
]);

it('separa uma chave valida', function () {
    expect(ApiKeyService::separar('fk_abc123abc123.seg.redo'))->toBe(['fk_abc123abc123', 'seg.redo']);
});
