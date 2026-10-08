<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Dados de um usuario do portal. A senha nunca aparece. */
class UsuarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'email' => $this->email,
            'papel' => $this->papel->value,
            'ativo' => $this->ativo,
            'ultimo_login_em' => $this->ultimo_login_em?->toIso8601String(),
        ];
    }
}
