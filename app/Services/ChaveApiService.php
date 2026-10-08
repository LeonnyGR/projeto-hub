<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChaveApi;
use App\Models\Cliente;
use Illuminate\Support\Facades\Log;

/**
 * Chaves de API dos clientes.
 *
 * Formato: "<prefixo>.<segredo>", por exemplo  fs_3fa91c0b2d4e.k8Jx...
 *  - prefixo: publico. Serve para achar a chave no banco (e, por ela, o cliente).
 *  - segredo: 32 bytes aleatorios. No banco guardamos so o sha256 dele.
 * O texto completo aparece uma unica vez, quando a chave e criada. Quem perder, cria outra e revoga a antiga.
 * Um cliente pode ter varias chaves ao mesmo tempo ("producao", "homologacao"...), cada uma revogavel sozinha.
 */
class ChaveApiService
{
    private const MARCA = 'fs_';

    private const TAMANHO_MAXIMO = 128;

    /** Cria uma chave para o cliente. Devolve [ChaveApi, 'texto completo da chave']. */
    public function criar(Cliente $cliente, string $nome): array
    {
        $prefixo = self::MARCA.bin2hex(random_bytes(6));
        $segredo = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $chave = $cliente->chaves()->create([
            'nome' => $nome,
            'prefixo' => $prefixo,
            'hash_segredo' => hash('sha256', $segredo),
        ]);

        Log::info('Chave de API criada', ['cliente_id' => $cliente->id, 'prefixo' => $prefixo]);

        return [$chave, $prefixo.'.'.$segredo];
    }

    public function revogar(ChaveApi $chave): void
    {
        $chave->update(['revogada_em' => now()]);

        Log::info('Chave de API revogada', ['cliente_id' => $chave->cliente_id, 'prefixo' => $chave->prefixo]);
    }

    /** Acha o cliente dono da chave. Devolve null se a chave nao for valida (sem dizer o motivo). */
    public function autenticar(?string $chaveCompleta): ?Cliente
    {
        [$prefixo, $segredo] = $this->separar($chaveCompleta);

        $chave = $prefixo === null ? null : ChaveApi::where('prefixo', $prefixo)->first();

        // Compara o hash mesmo quando a chave nao existe, para o tempo de resposta ser o mesmo.
        $esperado = $chave?->hash_segredo ?? str_repeat('0', 64);
        $confere = hash_equals($esperado, hash('sha256', $segredo ?? ''));

        if ($chave === null || ! $confere || $chave->revogada_em !== null) {
            return null;
        }

        $cliente = Cliente::find($chave->cliente_id);

        if ($cliente === null || ! $cliente->ativo) {
            return null;
        }

        // Para nao gravar no banco a cada requisicao, atualiza o "ultimo uso" no maximo a cada 5 minutos.
        if ($chave->ultimo_uso_em === null || $chave->ultimo_uso_em->lt(now()->subMinutes(5))) {
            $chave->forceFill(['ultimo_uso_em' => now()])->save();
        }

        return $cliente;
    }

    /** Separa "prefixo.segredo". Devolve [null, null] se o formato estiver errado. */
    private function separar(?string $chave): array
    {
        if ($chave === null || $chave === '' || strlen($chave) > self::TAMANHO_MAXIMO) {
            return [null, null];
        }

        $partes = explode('.', $chave, 2);

        if (count($partes) !== 2 || $partes[1] === '' || ! str_starts_with($partes[0], self::MARCA)) {
            return [null, null];
        }

        return $partes;
    }
}
