<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** Criar uma chave de API (POST /portal/chaves). */
class ChaveApiRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            // Para o dono se lembrar de onde a chave esta em uso: "producao", "homologacao"...
            'nome' => ['required', 'string', 'min:2', 'max:60'],
        ];
    }
}
