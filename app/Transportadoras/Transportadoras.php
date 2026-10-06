<?php

declare(strict_types=1);

namespace App\Transportadoras;

use App\Models\CredencialTransportadora;
use InvalidArgumentException;

/**
 * Lista das transportadoras que o sistema conhece e fabrica a classe certa para cada credencial.
 * Para adicionar uma transportadora nova, crie a classe e coloque o nome aqui.
 */
class Transportadoras
{
    public const LISTA = [
        'jadlog' => Jadlog::class,
        'correios' => Correios::class,
    ];

    public static function codigos(): array
    {
        return array_keys(self::LISTA);
    }

    /** $sellerId: seller em nome de quem a chamada e feita (so para o historico de chamadas). */
    public static function criar(CredencialTransportadora $credencial, ?string $sellerId = null): Transportadora
    {
        $classe = self::LISTA[$credencial->transportadora]
            ?? throw new InvalidArgumentException('Transportadora desconhecida: '.$credencial->transportadora);

        return new $classe($credencial, app(ClienteHttp::class), $sellerId);
    }

    /**
     * Servicos contratados que o seller cadastrou na configuracao: [['codigo' => '3', 'nome' => 'Package'], ...]
     * devolvidos como ['3' => 'Package'].
     */
    public static function servicosDaConfiguracao(array $configuracao): array
    {
        $servicos = [];
        foreach ($configuracao['servicos'] ?? [] as $servico) {
            $servicos[(string) $servico['codigo']] = (string) ($servico['nome'] ?? $servico['codigo']);
        }

        return $servicos;
    }

    /** Converte "3:Package,0:Expresso" para [['codigo' => '3', 'nome' => 'Package'], ['codigo' => '0', 'nome' => 'Expresso']]. */
    public static function lerServicosDigitados(string $texto): array
    {
        $servicos = [];
        foreach (array_filter(array_map('trim', explode(',', $texto))) as $parte) {
            [$codigo, $nome] = array_pad(explode(':', $parte, 2), 2, null);
            $servicos[] = ['codigo' => trim((string) $codigo), 'nome' => trim((string) ($nome ?? $codigo))];
        }

        return $servicos;
    }
}
