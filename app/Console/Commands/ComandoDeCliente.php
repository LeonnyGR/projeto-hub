<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ClienteApi;
use Illuminate\Console\Command;

/** Base dos comandos que mexem nos dados de um cliente da API (o B2C). */
abstract class ComandoDeCliente extends Command
{
    /**
     * Cliente informado no comando. Se nao informado e existir so um cliente ativo, usa esse.
     * Devolve null (e mostra o erro) se nao der para decidir.
     */
    protected function cliente(): ?ClienteApi
    {
        $id = $this->argument('cliente');

        if ($id !== null) {
            $cliente = ClienteApi::find((string) $id);
            if ($cliente === null) {
                $this->error('Cliente nao encontrado. Veja os ids com: cliente:list');
            }

            return $cliente;
        }

        $ativos = ClienteApi::where('ativo', true)->get();

        if ($ativos->count() === 1) {
            return $ativos->first();
        }

        $this->error($ativos->isEmpty()
            ? 'Nenhum cliente da API cadastrado. Crie um com: cliente:create'
            : 'Ha mais de um cliente da API. Informe o id (veja com: cliente:list).');

        return null;
    }
}
