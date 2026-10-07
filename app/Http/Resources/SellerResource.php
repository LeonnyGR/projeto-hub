<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SellerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'codigo_externo' => $this->codigo_externo,
            'cep_origem' => $this->cep_origem,
            'ativo' => $this->ativo,
            'criado_em' => $this->created_at->toIso8601String(),
        ];
    }
}
