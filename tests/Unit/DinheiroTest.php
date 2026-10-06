<?php

declare(strict_types=1);

use App\Support\Dinheiro;

it('converte reais (numero) para centavos sem erro de arredondamento', function (float|int|string $entrada, int $esperado) {
    expect(Dinheiro::deReais($entrada))->toBe($esperado);
})->with([
    [7.5, 750],
    [19.99, 1999],
    [0.29, 29],
    [1.005, 101],
    [10, 1000],
    ['25.34', 2534],
]);

it('converte texto brasileiro para centavos', function (string $entrada, int $esperado) {
    expect(Dinheiro::deTextoBrasileiro($entrada))->toBe($esperado);
})->with([
    ['19,92', 1992],
    ['1.019,92', 101992],
    ['0,79', 79],
    ['7,00', 700],
]);

it('formata centavos com ponto decimal', function () {
    expect(Dinheiro::paraDecimal(10050))->toBe('100.50')->and(Dinheiro::paraDecimal(5))->toBe('0.05');
});
