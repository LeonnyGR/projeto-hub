<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Uma chave de API, sem o segredo (so o prefixo, que ajuda a reconhecer a chave). */
class ChaveApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'prefixo' => $this->prefixo,
            'ultimo_uso_em' => $this->ultimo_uso_em?->toIso8601String(),
            'revogada_em' => $this->revogada_em?->toIso8601String(),
            'criada_em' => $this->created_at->toIso8601String(),
        ];
    }
}
