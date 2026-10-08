<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato do cliente com uma transportadora. As credenciais (token, senha) saem SO mascaradas ("••••••1234").
 * Para ver o valor inteiro o dono usa o portal (POST /portal/transportadoras/{t}/credenciais, com a senha dele).
 */
class TransportadoraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'transportadora' => $this->transportadora,
            'ativa' => $this->ativa,
            'credenciais_cadastradas' => true,
            'credenciais_mascaradas' => array_map(fn ($valor) => $this->mascarar((string) $valor), $this->credenciais ?? []),
            'configuracao' => $this->configuracao,
            'atualizado_em' => $this->updated_at->toIso8601String(),
        ];
    }

    /** Mostra so o final do valor (os 4 ultimos caracteres), e so quando ele e longo o bastante para isso nao ajudar a adivinhar. */
    private function mascarar(string $valor): string
    {
        $tamanho = mb_strlen($valor);

        return $tamanho >= 12 ? str_repeat('•', 6).mb_substr($valor, -4) : str_repeat('•', 8);
    }
}
