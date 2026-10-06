<?php

declare(strict_types=1);

namespace App\Support;

/** Conversao de valores das transportadoras para centavos (inteiro). Nunca guardamos float. */
class Dinheiro
{
    /** Numero em reais (ex.: 7.5 da Jadlog) => centavos. */
    public static function deReais(int|float|string $valor): int
    {
        return (int) round(((float) $valor) * 100);
    }

    /** Texto no formato brasileiro (ex.: "19,92" ou "1.019,92" dos Correios) => centavos. */
    public static function deTextoBrasileiro(string $texto): int
    {
        $limpo = str_replace(',', '.', str_replace('.', '', trim($texto)));

        return self::deReais($limpo);
    }

    /** Centavos => texto com ponto decimal, ex.: 10050 => "100.50". */
    public static function paraDecimal(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
