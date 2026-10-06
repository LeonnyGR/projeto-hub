<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Contrato do B2C com uma transportadora. As credenciais (token, senha) NUNCA saem pela API. */
class TransportadoraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'transportadora' => $this->transportadora,
            'ativa' => $this->ativa,
            'credenciais_cadastradas' => true,
            'configuracao' => $this->configuracao,
            'atualizado_em' => $this->updated_at->toIso8601String(),
        ];
    }
}
