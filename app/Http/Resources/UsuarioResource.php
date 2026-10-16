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
            // O dono pode tudo. Operador: o que foi liberado (so vem quando a lista de permissoes foi carregada).
            'acesso_total' => $this->ehDono(),
            'permissoes' => $this->when(! $this->ehDono() && $this->relationLoaded('permissoes'),
                fn () => $this->permissoes->map(fn ($p) => ['aplicacao' => $p->aplicacao_codigo, 'acoes' => $p->acoes])->all()),
            'ultimo_login_em' => $this->ultimo_login_em?->toIso8601String(),
        ];
    }
}
