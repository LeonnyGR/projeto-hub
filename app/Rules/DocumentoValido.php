<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** CPF (11 digitos) ou CNPJ (14 digitos) com digitos verificadores corretos. So numeros. */
class DocumentoValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $valor = (string) $value;

        $valido = match (strlen($valor)) {
            11 => self::cpfValido($valor),
            14 => self::cnpjValido($valor),
            default => false,
        };

        if (! $valido) {
            $fail('O :attribute deve ser um CPF ou CNPJ valido (somente numeros).');
        }
    }

    public static function cpfValido(string $cpf): bool
    {
        if (! ctype_digit($cpf) || strlen($cpf) !== 11 || count(array_unique(str_split($cpf))) === 1) {
            return false;
        }

        for ($tamanho = 9; $tamanho <= 10; $tamanho++) {
            $soma = 0;
            for ($i = 0; $i < $tamanho; $i++) {
                $soma += (int) $cpf[$i] * ($tamanho + 1 - $i);
            }
            $digito = ($soma * 10) % 11 % 10;
            if ($digito !== (int) $cpf[$tamanho]) {
                return false;
            }
        }

        return true;
    }

    public static function cnpjValido(string $cnpj): bool
    {
        if (! ctype_digit($cnpj) || strlen($cnpj) !== 14 || count(array_unique(str_split($cnpj))) === 1) {
            return false;
        }

        foreach ([12 => [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], 13 => [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $posicao => $pesos) {
            $soma = 0;
            foreach ($pesos as $i => $peso) {
                $soma += (int) $cnpj[$i] * $peso;
            }
            $resto = $soma % 11;
            $digito = $resto < 2 ? 0 : 11 - $resto;
            if ($digito !== (int) $cnpj[$posicao]) {
                return false;
            }
        }

        return true;
    }
}
