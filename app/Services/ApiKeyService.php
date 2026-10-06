<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ErroDeNegocio;
use App\Models\ChaveApi;
use App\Models\ClienteApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Chaves de API do cliente da API (o sistema que chama, hoje o B2C). Os sellers nao tem chave.
 *
 * Formato da chave: "<prefixo>.<segredo>"
 *  - prefixo: publico, serve para achar a chave no banco (ex.: fk_3fa91c0b2d4e)
 *  - segredo: 32 bytes aleatorios. No banco guardamos so o sha256 dele.
 * A chave completa aparece uma unica vez, quando e criada.
 *
 * Troca de chave: o cliente pode ter 2 chaves ativas ao mesmo tempo. A chave antiga
 * continua valendo por algumas horas, para o sistema ser atualizado sem parar.
 */
class ApiKeyService
{
    private const MARCA = 'fk_';

    private const TAMANHO_MAXIMO = 128;

    /** Gera uma chave nova (ainda sem salvar). */
    public static function gerar(): array
    {
        $prefixo = self::MARCA.bin2hex(random_bytes(6));
        $segredo = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['prefixo' => $prefixo, 'segredo' => $segredo, 'chave' => $prefixo.'.'.$segredo];
    }

    public static function hash(string $segredo): string
    {
        return hash('sha256', $segredo);
    }

    /** Separa "prefixo.segredo". Devolve [null, null] se o formato estiver errado. */
    public static function separar(?string $chave): array
    {
        if ($chave === null || $chave === '' || strlen($chave) > self::TAMANHO_MAXIMO) {
            return [null, null];
        }

        $partes = explode('.', $chave, 2);

        if (count($partes) !== 2 || $partes[1] === '' || ! str_starts_with($partes[0], self::MARCA)) {
            return [null, null];
        }

        return [$partes[0], $partes[1]];
    }

    /** Cria o cliente com a primeira chave. Devolve ['cliente' => ..., 'chave' => 'texto da chave']. */
    public function criarCliente(string $nome): array
    {
        return DB::transaction(function () use ($nome) {
            $cliente = ClienteApi::create(['nome' => $nome, 'ativo' => true]);
            $chave = $this->emitirChave($cliente);

            Log::info('Cliente da API criado', ['cliente_api_id' => $cliente->id]);

            return ['cliente' => $cliente, 'chave' => $chave];
        });
    }

    /** Cria e salva uma chave nova. Devolve o texto completo (unica vez que ele existe). */
    public function emitirChave(ClienteApi $cliente): string
    {
        $nova = self::gerar();

        $cliente->chaves()->create([
            'prefixo' => $nova['prefixo'],
            'hash_segredo' => self::hash($nova['segredo']),
        ]);

        Log::info('Chave de API criada', ['cliente_api_id' => $cliente->id, 'prefixo' => $nova['prefixo']]);

        return $nova['chave'];
    }

    /**
     * Troca de chave. Cria uma chave nova e:
     *  - normal: as chaves atuais continuam valendo por $horasDeTolerancia horas;
     *  - $revogarAtuais = true: as chaves atuais deixam de valer agora.
     */
    public function trocarChave(ClienteApi $cliente, bool $revogarAtuais = false, ?int $horasDeTolerancia = null): string
    {
        $horasDeTolerancia ??= (int) config('frete.api_key.tolerancia_horas');
        $maximo = (int) config('frete.api_key.max_ativas');

        return DB::transaction(function () use ($cliente, $revogarAtuais, $horasDeTolerancia, $maximo) {
            $ativas = $cliente->chaves()->ativas()->lockForUpdate()->get();

            if ($revogarAtuais) {
                foreach ($ativas as $chave) {
                    $chave->update(['revogada_em' => now()]);
                }
            } else {
                if ($ativas->count() >= $maximo) {
                    throw new ErroDeNegocio('limite_de_chaves', "O cliente ja tem {$maximo} chaves ativas. Use --revoke-old ou espere uma expirar.", 409);
                }

                $limite = now()->addHours($horasDeTolerancia);
                foreach ($ativas as $chave) {
                    if ($chave->expira_em === null || $chave->expira_em->gt($limite)) {
                        $chave->update(['expira_em' => $limite]);
                    }
                }
            }

            Log::info('Chave de API trocada', ['cliente_api_id' => $cliente->id, 'revogou_atuais' => $revogarAtuais]);

            return $this->emitirChave($cliente);
        });
    }

    public function desabilitar(ClienteApi $cliente, bool $ativo = false): void
    {
        $cliente->update(['ativo' => $ativo]);

        Log::info($ativo ? 'Cliente da API habilitado' : 'Cliente da API desabilitado', ['cliente_api_id' => $cliente->id]);
    }

    /** Acha o cliente dono da chave. Devolve null se a chave nao for valida (sem dizer o motivo). */
    public function autenticar(?string $chaveCompleta): ?ClienteApi
    {
        [$prefixo, $segredo] = self::separar($chaveCompleta);

        $chave = $prefixo === null ? null : ChaveApi::where('prefixo', $prefixo)->first();

        // Calcula e compara o hash mesmo quando a chave nao existe, para o tempo de resposta ser o mesmo.
        $esperado = $chave?->hash_segredo ?? str_repeat('0', 64);
        $confere = hash_equals($esperado, self::hash($segredo ?? ''));

        if ($chave === null || ! $confere || $chave->revogada_em !== null
            || ($chave->expira_em !== null && $chave->expira_em->isPast())) {
            return null;
        }

        $cliente = ClienteApi::find($chave->cliente_api_id);

        if ($cliente === null || ! $cliente->ativo) {
            return null;
        }

        // Para nao gravar no banco a cada requisicao, atualiza o "ultimo uso" no maximo a cada 5 minutos.
        if ($chave->ultimo_uso_em === null || $chave->ultimo_uso_em->lt(now()->subMinutes(5))) {
            $chave->forceFill(['ultimo_uso_em' => now()])->save();
        }

        return $cliente;
    }
}
