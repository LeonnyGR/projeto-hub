<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Transportadoras\Transportadoras;
use Illuminate\Validation\Rule;

class PontoRetiradaRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->query('cep'))) {
            $this->merge(['cep' => preg_replace('/\D/', '', $this->query('cep'))]);
        }
    }

    public function rules(): array
    {
        return [
            'cep' => ['required', 'string', 'regex:/^\d{8}$/'],
            // Opcional: so uma transportadora. Sem isso, consulta todas as que oferecem pontos de retirada.
            'transportadora' => ['sometimes', 'string', Rule::in(Transportadoras::codigos())],
            // Opcional: tipos separados por virgula (ex.: LOCKER,PUBLICO). Cada transportadora usa os que conhece.
            'tipos' => ['sometimes', 'string', 'max:100', 'regex:/^[A-Za-z_]+(,[A-Za-z_]+)*$/'],
        ];
    }
}
