<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** Cadastrar (POST) ou atualizar (PATCH) um seller. */
class SellerRequest extends ApiFormRequest
{
    /** CEP pode vir com hifen ("01310-100"): guardamos so os numeros. */
    protected function prepareForValidation(): void
    {
        $cep = is_string($this->input('cep_origem')) ? preg_replace('/\D/', '', $this->input('cep_origem')) : '';

        // Sem nenhum numero ("abc") mantemos o valor original para a validacao reclamar dele.
        if ($cep !== '') {
            $this->merge(['cep_origem' => $cep]);
        }
    }

    public function rules(): array
    {
        if ($this->isMethod('PATCH')) {
            return [
                'nome' => ['sometimes', 'string', 'min:2', 'max:120'],
                'cep_origem' => ['sometimes', 'string', 'regex:/^\d{8}$/'],
                'ativo' => ['sometimes', 'boolean'],
            ];
        }

        return [
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            // CEP de onde o seller despacha: e a origem das cotacoes quando a cotacao nao informar outro.
            'cep_origem' => ['required', 'string', 'regex:/^\d{8}$/'],
            // Id do seller no B2C. Se ja existir, a API responde 409 com o id que ja temos.
            'codigo_externo' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }
}
