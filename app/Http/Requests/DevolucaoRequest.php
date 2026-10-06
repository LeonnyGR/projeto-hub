<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** Criar uma devolucao de uma remessa ja entregue. O header Idempotency-Key e obrigatorio. */
class DevolucaoRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'remessa_id' => ['required', 'uuid'],
            'motivo' => ['required', 'string', 'min:3', 'max:200'],
        ];
    }
}
