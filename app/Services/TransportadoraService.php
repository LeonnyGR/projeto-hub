<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cliente;
use App\Models\CredencialTransportadora;
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

        $credencial = $cliente->credenciais()->updateOrCreate(['transportadora' => $transportadora], $campos + ['ativa' => true]);

        // O log diz o que mudou, nunca o conteudo (tem segredo).
        Log::info('Transportadora configurada', ['cliente_id' => $cliente->id, 'transportadora' => $transportadora]);

        return $credencial;
    }
}
