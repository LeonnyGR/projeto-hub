<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Situacao de uma remessa (envio ou devolucao).
 *
 * criando -> criada -> despachada -> em_transito -> saiu_para_entrega -> entregue
 *               \          \
 *                +----------+--> cancelada   (so antes de o pacote entrar em transito)
 */
enum StatusRemessa: string
{
    case Criando = 'criando';
    case Criada = 'criada';
    case Despachada = 'despachada';
    case EmTransito = 'em_transito';
    case SaiuParaEntrega = 'saiu_para_entrega';
    case Entregue = 'entregue';
    case Cancelada = 'cancelada';

    public function podeDespachar(): bool
    {
        return $this === self::Criada;
    }

    public function podeCancelar(): bool
    {
        return in_array($this, [self::Criada, self::Despachada], true);
    }

    /** Remessas cujo rastreio ainda precisa ser atualizado. */
    public function acompanhar(): bool
    {
        return in_array($this, [self::Despachada, self::EmTransito, self::SaiuParaEntrega], true);
    }
}
