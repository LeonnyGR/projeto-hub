<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CotacaoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cep_origem' => $this->cep_origem,
            'cep_destino' => $this->cep_destino,
            'expira_em' => $this->expira_em->toIso8601String(),
            // Ja ordenadas: a mais barata primeiro.
            'opcoes' => $this->resultados['opcoes'],
            // Transportadoras que nao responderam (a cotacao sai com as demais).
            'falhas' => $this->resultados['falhas'],
        ];
    }
}
