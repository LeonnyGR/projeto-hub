<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** PUT /usuarios/{id}/permissoes: a lista COMPLETA do que o operador pode fazer (lista vazia = nada). */
class PermissoesRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'permissoes' => ['present', 'array', 'max:50'],
            'permissoes.*.aplicacao' => ['required', 'string', 'max:12'],
            'permissoes.*.acoes' => ['required', 'array', 'max:10'],
            'permissoes.*.acoes.*' => ['required', 'string', 'max:20'],
        ];
    }
}
