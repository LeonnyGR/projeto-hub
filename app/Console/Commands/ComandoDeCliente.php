<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cliente;
use Illuminate\Console\Command;

/** Base dos comandos que mexem nos dados de um cliente. */
abstract class ComandoDeCliente extends Command
{
    /**
     * Cliente informado no comando (id do cliente). Se nao informado e existir so um cliente ativo, usa esse.
     * Devolve null (e mostra o erro) se nao der para decidir.
     */
    protected function cliente(): ?Cliente
    {
        $id = $this->argument('cliente');

        if ($id !== null) {
            $cliente = Cliente::find((string) $id);
            if ($cliente === null) {
                $this->error('Cliente nao encontrado. Veja os ids com: GET /admin/clientes');
            }

            return $cliente;
        }

        $ativos = Cliente::where('ativo', true)->get();

        if ($ativos->count() === 1) {
            return $ativos->first();
        }

        $this->error($ativos->isEmpty()
            ? 'Nenhum cliente cadastrado. Crie um com: POST /admin/clientes'
            : 'Ha mais de um cliente. Informe o id (veja com: GET /admin/clientes).');

        return null;
    }
}
