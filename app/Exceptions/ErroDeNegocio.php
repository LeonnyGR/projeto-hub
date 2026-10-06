<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Erro previsto, com mensagem segura para mostrar ao cliente da API.
 * A mensagem NUNCA pode ter SQL, dado pessoal ou detalhe de transportadora.
 *
 * Exemplo: throw new ErroDeNegocio('cotacao_expirada', 'A cotacao expirou. Faca uma nova.', 422);
 */
class ErroDeNegocio extends RuntimeException
{
    public function __construct(
        private readonly string $codigoErro,
        string $mensagem,
        private readonly int $statusHttp = 422,
        private readonly array $detalhes = [],
    ) {
        parent::__construct($mensagem);
    }

    public function codigoErro(): string
    {
        return $this->codigoErro;
    }

    public function statusHttp(): int
    {
        return $this->statusHttp;
    }

    public function detalhes(): array
    {
        return $this->detalhes;
    }
}
