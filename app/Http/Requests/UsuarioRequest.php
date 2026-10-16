<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/** O dono do cliente cadastra (POST) ou atualiza (PATCH) um operador. */
class UsuarioRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        if ($this->isMethod('PATCH')) {
            // Bloquear/liberar ('ativo') e redefinir a senha. O e-mail nao muda.
            return [
                'nome' => ['sometimes', 'string', 'min:2', 'max:120'],
                'ativo' => ['sometimes', 'boolean'],
                'password' => ['sometimes', 'string', 'min:10', 'max:200'],
            ];
        }

        return [
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('usuarios', 'email')],
            'password' => ['required', 'string', 'min:10', 'max:200'],
            'permissoes' => ['sometimes', 'array', 'max:50'],
            'permissoes.*.aplicacao' => ['required', 'string', 'max:12'],
            'permissoes.*.acoes' => ['required', 'array', 'max:10'],
            'permissoes.*.acoes.*' => ['required', 'string', 'max:20'],
        ];
    }
}
