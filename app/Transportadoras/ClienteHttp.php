<?php

declare(strict_types=1);

namespace App\Transportadoras;

use App\Models\ChamadaTransportadora;
use App\Support\Mascaramento;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Unico lugar que faz chamadas HTTP as transportadoras. Garante em todas:
 *  - timeout curto, sem seguir redirecionamentos, TLS sempre verificado;
 *  - registro da chamada em chamadas_transportadoras (com dados sensiveis mascarados).
 *
 * Um "pedido" e um array: ['metodo', 'url', 'headers', 'query', 'corpo', 'basic' => [usuario, senha]].
 */
class ClienteHttp
{
    /** Envia uma chamada. Devolve a resposta, ou null se nao houve resposta (timeout, conexao). */
    public function enviar(?string $clienteId, string $transportadora, string $operacao, array $pedido): ?Response
    {
        $inicio = microtime(true);

        try {
            $resposta = $this->preparar(Http::withOptions([]), $pedido)
                ->send($pedido['metodo'], $pedido['url'], $this->opcoes($pedido));
        } catch (ConnectionException $e) {
            $this->registrar($clienteId, $transportadora, $operacao, $pedido, null, $inicio, 'sem_resposta');

            return null;
        }

        $this->registrar($clienteId, $transportadora, $operacao, $pedido, $resposta, $inicio);

        return $resposta;
    }

    /**
     * Envia varias chamadas ao mesmo tempo (usado na cotacao).
     *
     * @param  array  $pedidos  lista de ['transportadora' => 'jadlog', 'pedido' => [...]] ; a chave de cada item precisa ser unica
     * @return array  [chave => Response|null]
     */
    public function enviarEmParalelo(?string $clienteId, string $operacao, array $pedidos): array
    {
        $inicio = microtime(true);

        $respostas = Http::pool(function (Pool $pool) use ($pedidos) {
            $chamadas = [];
            foreach ($pedidos as $chave => $item) {
                $pedido = $item['pedido'];
                $chamadas[] = $this->preparar($pool->as($chave), $pedido)
                    ->send($pedido['metodo'], $pedido['url'], $this->opcoes($pedido));
            }

            return $chamadas;
        });

        $saida = [];
        foreach ($pedidos as $chave => $item) {
            $resposta = $respostas[$chave] ?? null;
            $resposta = $resposta instanceof Response ? $resposta : null; // excecao de conexao => sem resposta

            $this->registrar($clienteId, $item['transportadora'], $operacao, $item['pedido'], $resposta, $inicio, $resposta === null ? 'sem_resposta' : null);
            $saida[$chave] = $resposta;
        }

        return $saida;
    }

    private function preparar(PendingRequest $http, array $pedido): PendingRequest
    {
        $http = $http
            ->timeout((int) config('transportadoras.timeout_segundos'))
            ->connectTimeout((int) config('transportadoras.conexao_timeout_segundos'))
            ->withoutRedirecting()
            ->acceptJson()
            ->withHeaders($pedido['headers'] ?? []);

        if (! empty($pedido['basic'])) {
            $http = $http->withBasicAuth($pedido['basic'][0], $pedido['basic'][1]);
        }

        return $http;
    }

    private function opcoes(array $pedido): array
    {
        $opcoes = [];
        if (! empty($pedido['query'])) {
            $opcoes['query'] = $pedido['query'];
        }
        if (($pedido['corpo'] ?? null) !== null) {
            $opcoes['json'] = $pedido['corpo'];
        }

        return $opcoes;
    }

    private function registrar(?string $clienteId, string $transportadora, string $operacao, array $pedido, ?Response $resposta, float $inicio, ?string $erro = null): void
    {
        try {
            $corpoResposta = $resposta?->body();

            ChamadaTransportadora::create([
                'cliente_id' => $clienteId,
                'transportadora' => $transportadora,
                'operacao' => $operacao,
                'metodo' => $pedido['metodo'],
                'url' => mb_substr(Mascaramento::url($pedido['url']), 0, 300),
                'requisicao' => isset($pedido['corpo']) ? Mascaramento::texto((string) json_encode($pedido['corpo'])) : null,
                'resposta' => $corpoResposta === null ? null : mb_substr((string) Mascaramento::texto($corpoResposta), 0, 20000),
                'status_http' => $resposta?->status(),
                'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
                'erro' => $erro,
                'request_id' => Context::get('request_id'),
            ]);
        } catch (Throwable $e) {
            // Falha ao gravar o historico nunca pode derrubar a operacao principal.
            Log::warning('Nao foi possivel registrar chamada a transportadora', ['transportadora' => $transportadora, 'erro' => $e::class]);
        }
    }
}
