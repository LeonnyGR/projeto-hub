<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ClienteApi;
use App\Services\ApiKeyService;
use Illuminate\Console\Command;

class DesabilitarClienteApi extends Command
{
    protected $signature = 'cliente:disable {cliente : ID do cliente} {--enable : Habilita de novo}';

    protected $description = 'Desabilita (ou habilita) um cliente da API. Cliente desabilitado nao autentica com nenhuma chave';

    public function handle(ApiKeyService $chaves): int
    {
        $cliente = ClienteApi::find((string) $this->argument('cliente'));

        if ($cliente === null) {
            $this->error('Cliente nao encontrado.');

            return self::FAILURE;
        }

        $habilitar = (bool) $this->option('enable');
        $chaves->desabilitar($cliente, $habilitar);

        $this->info($habilitar ? 'Cliente habilitado.' : 'Cliente desabilitado.');

        return self::SUCCESS;
    }
}
