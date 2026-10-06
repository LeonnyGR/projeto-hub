<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** Cadastrar (POST) ou atualizar (PATCH) um seller. */
class SellerRequest extends ApiFormRequest
{
    public function rules(): array
    {
        if ($this->isMethod('PATCH')) {
            return [
                'nome' => ['sometimes', 'string', 'min:2', 'max:120'],
                'ativo' => ['sometimes', 'boolean'],
            ];
        }

        return [
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            // Id do seller no B2C. Se ja existir, a API responde 409 com o id que ja temos.
            'codigo_externo' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }
}
