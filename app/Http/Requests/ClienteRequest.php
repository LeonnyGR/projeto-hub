<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\DocumentoValido;
use Illuminate\Validation\Rule;

/** Cadastrar (POST, junto com o dono) ou atualizar (PATCH) um cliente. Usado so pela administracao. */
class ClienteRequest extends ApiFormRequest
{
    /** CNPJ pode vir com mascara ("12.345.678/0001-95"): guardamos so os numeros. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('cnpj'))) {
            $this->merge(['cnpj' => preg_replace('/\D/', '', $this->input('cnpj'))]);
        }

        if (is_array($this->input('dono')) && is_string($this->input('dono.email'))) {
            $this->merge(['dono' => ['email' => strtolower(trim($this->input('dono.email')))] + $this->input('dono')]);
        }
    }

    public function rules(): array
    {
        if ($this->isMethod('PATCH')) {
            return [
                'nome' => ['sometimes', 'string', 'min:2', 'max:120'],
                'cnpj' => ['sometimes', ...$this->regrasDoCnpj($this->route('id'))],
                'ativo' => ['sometimes', 'boolean'],
            ];
        }

        return [
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            'cnpj' => ['required', ...$this->regrasDoCnpj()],
            // Primeiro usuario do cliente (o dono, adm_tenant).
            'dono' => ['required', 'array'],
            'dono.nome' => ['required', 'string', 'min:2', 'max:120'],
            'dono.email' => ['required', 'email:rfc', 'max:190', Rule::unique('usuarios', 'email')],
            'dono.password' => ['required', 'string', 'min:10', 'max:200'],
        ];
    }

    /** CNPJ valido (digitos verificadores) e que nenhum outro cliente use. */
    private function regrasDoCnpj(?string $ignorarId = null): array
    {
        return [
            'string',
            fn (string $campo, mixed $valor, \Closure $falhar) => DocumentoValido::cnpjValido((string) $valor) ? null : $falhar('O cnpj deve ser um CNPJ valido.'),
            Rule::unique('clientes', 'cnpj')->ignore($ignorarId),
        ];
    }
}
