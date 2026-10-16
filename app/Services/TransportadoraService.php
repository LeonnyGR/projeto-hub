<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ErroDeNegocio;
use App\Models\Cliente;
use App\Models\CredencialTransportadora;
use App\Transportadoras\Transportadoras;
use App\Transportadoras\TransportadoraIndisponivel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Cadastro das credenciais de um cliente com as transportadoras. */
class TransportadoraService
{
    /**
     * Cadastra ou atualiza as credenciais do cliente com uma transportadora
     * (chamado pela API, PUT /transportadoras/{t}, e pelo comando transportadora:configurar).
     * Os dados secretos ficam criptografados.
     */
    public function configurar(Cliente $cliente, string $transportadora, array $dados): CredencialTransportadora
    {
        // Editar so um campo da credencial (ex.: so a senha): o resto continua como estava salvo.
        $existente = $cliente->credenciais()->where('transportadora', $transportadora)->first();
        if ($existente !== null && isset($dados['credenciais'])) {
            $dados['credenciais'] = array_merge($existente->credenciais, $dados['credenciais']);
        }

        // Campos que nao vieram (ex.: edicao sem reenviar o segredo, ou sem "ativa") ficam como estavam.
        $campos = ['configuracao' => $dados['configuracao']];
        foreach (['credenciais', 'ativa'] as $campo) {
            if (array_key_exists($campo, $dados)) {
                $campos[$campo] = $dados[$campo];
            }
        }

        // Se a transportadora sabe conferir a credencial (Correios), conferimos antes de aceitar:
        // credencial recusada desfaz o cadastro. Fora do ar, nao da para saber: salva e avisa no log.
        $credencial = DB::transaction(function () use ($cliente, $transportadora, $campos) {
            $credencial = $cliente->credenciais()->updateOrCreate(['transportadora' => $transportadora], $campos + ['ativa' => true]);

            $integracao = Transportadoras::criar($credencial, $cliente->id);
            if (method_exists($integracao, 'validarCredenciais')) {
                try {
                    $integracao->validarCredenciais();
                } catch (TransportadoraIndisponivel $e) {
                    if ($e->motivo === 'credencial_invalida') {
                        throw new ErroDeNegocio('credencial_invalida', 'A transportadora recusou as credenciais informadas. Confira usuario, senha e cartao de postagem.', 422);
                    }
                    Log::warning('Credencial salva sem conferir', ['cliente_id' => $cliente->id, 'transportadora' => $transportadora, 'motivo' => $e->motivo]);
                }
            }

            return $credencial;
        });

        // O log diz o que mudou, nunca o conteudo (tem segredo).
        Log::info('Transportadora configurada', ['cliente_id' => $cliente->id, 'transportadora' => $transportadora]);

        return $credencial;
    }
}
