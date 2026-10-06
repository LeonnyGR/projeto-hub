<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ErroDeNegocio;
use App\Models\ClienteApi;
use App\Models\CredencialTransportadora;
use App\Models\Seller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/** Cadastro de sellers e das credenciais do cliente da API (B2C) com as transportadoras. */
class SellerService
{
    /**
     * @param  array  $dados  nome e, opcionalmente, codigo_externo
     *
     * @throws ErroDeNegocio 409 se este cliente ja tem seller com o mesmo codigo_externo (o id dele vem em "detalhes")
     */
    public function criar(ClienteApi $cliente, array $dados): Seller
    {
        $codigo = $dados['codigo_externo'] ?? null;

        if ($codigo !== null && ($existente = $cliente->sellers()->where('codigo_externo', $codigo)->first())) {
            throw $this->jaExiste($existente);
        }

        try {
            $seller = $cliente->sellers()->create(['nome' => $dados['nome'], 'codigo_externo' => $codigo, 'ativo' => true]);
        } catch (UniqueConstraintViolationException) {
            // duas chamadas ao mesmo tempo com o mesmo codigo_externo
            throw $this->jaExiste($cliente->sellers()->where('codigo_externo', $codigo)->firstOrFail());
        }

        Log::info('Seller criado', ['seller_id' => $seller->id]);

        return $seller;
    }

    public function atualizar(Seller $seller, array $dados): Seller
    {
        $seller->update($dados);

        Log::info('Seller atualizado', ['seller_id' => $seller->id, 'campos' => array_keys($dados)]);

        return $seller;
    }

    /**
     * Cadastra ou atualiza as credenciais do cliente da API (B2C) com uma transportadora
     * (chamado pelo comando transportadora:configurar). Todos os sellers do cliente passam a usar estes dados.
     * Os dados secretos ficam criptografados.
     */
    public function configurarTransportadora(ClienteApi $cliente, string $transportadora, array $dados): CredencialTransportadora
    {
        $credencial = $cliente->credenciais()->updateOrCreate(
            ['transportadora' => $transportadora],
            [
                'ativa' => $dados['ativa'] ?? true,
                'credenciais' => $dados['credenciais'],
                'configuracao' => $dados['configuracao'],
            ],
        );

        // O log diz o que mudou, nunca o conteudo (tem segredo).
        Log::info('Transportadora configurada', ['cliente_api_id' => $cliente->id, 'transportadora' => $transportadora]);

        return $credencial;
    }

    private function jaExiste(Seller $existente): ErroDeNegocio
    {
        return new ErroDeNegocio('seller_ja_existe', 'Ja existe um seller com este codigo_externo.', 409, ['seller_id' => $existente->id]);
    }
}
