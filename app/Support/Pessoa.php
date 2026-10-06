<?php

declare(strict_types=1);

namespace App\Support;

use App\Rules\DocumentoValido;
use Illuminate\Validation\Rule;

/**
 * Uma "pessoa" (remetente, destinatario ou tomador do frete): nome, documento, telefone, e-mail e endereco.
 * Usada para validar e normalizar os dados em mais de um lugar.
 */
class Pessoa
{
    private const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    /**
     * Documento, telefone e CEP podem vir com mascara ("11.222.333/0001-81"): fica so com os numeros.
     * UF em maiuscula.
     */
    public static function normalizar(mixed $pessoa): mixed
    {
        if (! is_array($pessoa)) {
            return $pessoa;
        }

        foreach (['documento', 'telefone'] as $campo) {
            if (isset($pessoa[$campo]) && is_string($pessoa[$campo])) {
                $pessoa[$campo] = preg_replace('/\D/', '', $pessoa[$campo]);
            }
        }
        if (isset($pessoa['endereco']['cep']) && is_string($pessoa['endereco']['cep'])) {
            $pessoa['endereco']['cep'] = preg_replace('/\D/', '', $pessoa['endereco']['cep']);
        }
        if (isset($pessoa['endereco']['uf']) && is_string($pessoa['endereco']['uf'])) {
            $pessoa['endereco']['uf'] = strtoupper($pessoa['endereco']['uf']);
        }

        return $pessoa;
    }

    /** Regras de validacao. $campo e o caminho do campo ("remetente", "configuracao.tomador"). Limites iguais aos do PDF da Jadlog. */
    public static function regras(string $campo): array
    {
        return [
            $campo => ['required', 'array'],
            "{$campo}.nome" => ['required', 'string', 'min:2', 'max:60'],
            "{$campo}.documento" => ['required', 'string', new DocumentoValido()],
            "{$campo}.telefone" => ['required', 'string', 'regex:/^\d{10,11}$/'],
            "{$campo}.email" => ['nullable', 'email', 'max:100'],
            "{$campo}.endereco" => ['required', 'array'],
            "{$campo}.endereco.cep" => ['required', 'string', 'regex:/^\d{8}$/'],
            "{$campo}.endereco.logradouro" => ['required', 'string', 'min:2', 'max:60'],
            "{$campo}.endereco.numero" => ['required', 'string', 'max:10'],
            "{$campo}.endereco.complemento" => ['nullable', 'string', 'max:20'],
            "{$campo}.endereco.bairro" => ['required', 'string', 'min:2', 'max:60'],
            "{$campo}.endereco.cidade" => ['required', 'string', 'max:60'],
            "{$campo}.endereco.uf" => ['required', 'string', Rule::in(self::UFS)],
        ];
    }
}
