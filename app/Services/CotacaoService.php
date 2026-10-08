<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ErroDeNegocio;
use App\Models\Cotacao;
use App\Models\Cliente;
use App\Transportadoras\ClienteHttp;
use App\Transportadoras\TransportadoraIndisponivel;
use App\Transportadoras\Transportadoras;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cota o frete em todas as transportadoras ativas do cliente, ao mesmo tempo.
 * Se uma transportadora falhar, as outras continuam: a cotacao sai so com quem respondeu
 * e a falha aparece em "falhas".
 */
class CotacaoService
{
    public function __construct(private readonly ClienteHttp $http)
    {
    }

    /** @param array $dados cep_origem, cep_destino, valor_declarado_centavos, volumes[] (ja validados) */
    public function cotar(Cliente $cliente, array $dados): Cotacao
    {
        $credenciais = $cliente->credenciais()->where('ativa', true)->get();

        if ($credenciais->isEmpty()) {
            throw new ErroDeNegocio('sem_transportadoras', 'Nenhuma transportadora configurada.', 422);
        }

        // 1. Cada transportadora descreve as chamadas que precisa fazer.
        $transportadoras = [];
        $pedidos = [];
        $falhas = [];

        foreach ($credenciais as $credencial) {
            try {
                $transportadora = Transportadoras::criar($credencial, $cliente->id);

                foreach ($transportadora->pedidosDeCotacao($dados) as $pedido) {
                    $pedidos[$transportadora->codigo().':'.$pedido['chave']] = [
                        'transportadora' => $transportadora->codigo(),
                        'pedido' => $pedido,
                    ];
                }

                $transportadoras[$transportadora->codigo()] = $transportadora;
            } catch (Throwable $e) {
                $falhas[] = $this->falha($credencial->transportadora, $e);
            }
        }

        // 2. Todas as chamadas saem juntas (cada uma com timeout de poucos segundos).
        $respostas = $pedidos === [] ? [] : $this->http->enviarEmParalelo($cliente->id, 'cotacao', $pedidos);

        // 3. Cada transportadora le as proprias respostas.
        $opcoes = [];

        foreach ($transportadoras as $codigo => $transportadora) {
            $dela = [];
            foreach ($pedidos as $chaveCompleta => $item) {
                if ($item['transportadora'] === $codigo) {
                    $dela[$item['pedido']['chave']] = $respostas[$chaveCompleta] ?? null;
                }
            }

            try {
                foreach ($transportadora->lerCotacao($dados, $dela) as $opcao) {
                    $opcoes[] = ['transportadora' => $codigo] + $opcao;
                }
            } catch (Throwable $e) {
                $falhas[] = $this->falha($codigo, $e);
            }
        }

        // 4. Mais barata primeiro; em empate, a mais rapida.
        usort($opcoes, fn (array $a, array $b) => [$a['valor_centavos'], $a['prazo_dias'] ?? PHP_INT_MAX]
            <=> [$b['valor_centavos'], $b['prazo_dias'] ?? PHP_INT_MAX]);

        return $cliente->cotacoes()->create([
            'cep_origem' => $dados['cep_origem'],
            'cep_destino' => $dados['cep_destino'],
            'requisicao' => $dados,
            'resultados' => ['opcoes' => $opcoes, 'falhas' => $falhas],
            'expira_em' => now()->addMinutes((int) config('frete.cotacao.validade_minutos')),
        ]);
    }

    /** Falha mostrada ao cliente: so o codigo da transportadora e um motivo curto e seguro. */
    private function falha(string $transportadora, Throwable $e): array
    {
        if ($e instanceof TransportadoraIndisponivel) {
            return ['transportadora' => $transportadora, 'motivo' => $e->motivo];
        }

        // Bug nosso: vai para o log, e o cliente ve so "erro_interno".
        Log::error('Erro inesperado ao cotar', ['transportadora' => $transportadora, 'erro' => $e::class, 'mensagem' => $e->getMessage()]);

        return ['transportadora' => $transportadora, 'motivo' => 'erro_interno'];
    }
}
