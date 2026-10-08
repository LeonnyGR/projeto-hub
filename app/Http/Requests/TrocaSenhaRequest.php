<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** O usuario troca a propria senha (PATCH /portal/eu/senha). */
class TrocaSenhaRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'senha_atual' => ['required', 'string', 'max:200'],
            'nova_senha' => ['required', 'string', 'min:10', 'max:200', 'different:senha_atual'],
        ];
    }
}
