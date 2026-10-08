<?php

declare(strict_types=1);

namespace App\Transportadoras;

use App\Exceptions\ErroDeNegocio;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A transportadora nao respondeu como esperado (fora do ar, lenta, credencial recusada, resposta estranha).
 * $motivo e um codigo curto e seguro para devolver ao cliente:
 *   sem_resposta | credencial_invalida | resposta_invalida | erro_na_transportadora | sem_cobertura
 */
class TransportadoraIndisponivel extends RuntimeException
{
    public function __construct(public readonly string $motivo, string $detalheInterno = '')
    {
        parent::__construct($detalheInterno !== '' ? $detalheInterno : $motivo);
    }

    /** Versao segura para devolver ao cliente (HTTP 502): so o motivo, sem detalhes da transportadora. */
    public function paraErroDeNegocio(): ErroDeNegocio
    {
        return new ErroDeNegocio('transportadora_indisponivel', 'A transportadora nao respondeu. Tente novamente.', 502, ['motivo' => $this->motivo]);
    }

    /**
     * Olha a resposta e diz se ela indica problema da transportadora (e nao um erro de negocio).
     * Devolve o motivo, ou null se a resposta pode ser lida normalmente.
     */
    public static function motivoDaResposta(?Response $resposta): ?string
    {
        return match (true) {
            $resposta === null => 'sem_resposta',
            in_array($resposta->status(), [401, 403], true) => 'credencial_invalida',
            $resposta->status() >= 500 => 'erro_na_transportadora',
            default => null,
        };
    }
}
