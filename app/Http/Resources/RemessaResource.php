<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** Envio ou devolucao. */
class RemessaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $devolucao = $this->tipo === 'devolucao';

        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'status' => $this->status->value,
            'transportadora' => $this->transportadora,
            'servico_codigo' => $this->servico_codigo,
            // Na devolucao o valor vem do contrato com a transportadora e nao e calculado aqui.
            'valor_frete_centavos' => $this->valor_frete_centavos,
            'prazo_dias' => $this->prazo_dias,
            'codigo_rastreio' => $this->codigo_rastreio,
            'cotacao_id' => $this->when(! $devolucao, $this->cotacao_id),
            'remessa_origem_id' => $this->when($devolucao, $this->remessa_origem_id),
            'motivo' => $this->when($devolucao, $this->motivo),
            'remetente' => $this->remetente,
            'destinatario' => $this->destinatario,
            // Um item por volume. Na Jadlog todos os volumes tem o mesmo codigo; nos Correios, cada um tem o seu.
            'volumes' => collect($this->volumes)
                ->map(fn (array $v) => Arr::only($v, ['peso_gramas', 'altura_cm', 'largura_cm', 'comprimento_cm', 'codigo_rastreio']))
                ->all(),
            // Jadlog: a etiqueta chega na criacao. Correios: e gerada na primeira consulta de /etiqueta.
            'etiqueta_disponivel' => $this->etiqueta_caminho !== null,
            'despachada_em' => $this->despachada_em?->toIso8601String(),
            'cancelada_em' => $this->cancelada_em?->toIso8601String(),
            'criada_em' => $this->created_at->toIso8601String(),
        ];
    }
}
