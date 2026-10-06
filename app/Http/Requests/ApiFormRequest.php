<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Base dos Form Requests. Alem das regras de cada request, rejeita campos que nao existem em rules()
 * (evita erro de digitacao passar em silencio). Para aceitar campos extras numa rota:
 * defina `protected bool $rejeitaCamposDesconhecidos = false;` no request dela.
 */
abstract class ApiFormRequest extends FormRequest
{
    protected bool $rejeitaCamposDesconhecidos = true;

    public function authorize(): bool
    {
        return true; // a rota ja exige seller autenticado
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $this->rejeitaCamposDesconhecidos) {
                return;
            }

            // "volumes.*.peso" => campo de primeiro nivel "volumes"
            $permitidos = [];
            foreach (array_keys($this->rules()) as $regra) {
                $permitidos[explode('.', (string) $regra)[0]] = true;
            }

            foreach (array_keys($this->all()) as $campo) {
                if (! isset($permitidos[$campo])) {
                    $validator->errors()->add((string) $campo, 'Campo desconhecido.');
                }
            }
        }];
    }
}
