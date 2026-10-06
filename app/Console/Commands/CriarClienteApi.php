<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ApiKeyService;
use Illuminate\Console\Command;

class CriarClienteApi extends Command
{
    protected $signature = 'cliente:create {nome : Nome do sistema que vai chamar a API (ex.: "B2C")}';

    protected $description = 'Cria um cliente da API (o sistema que chama, ex.: o B2C) e a primeira chave (mostrada uma unica vez)';

    public function handle(ApiKeyService $chaves): int
    {
        $resultado = $chaves->criarCliente((string) $this->argument('nome'));

        $this->info('Cliente criado: '.$resultado['cliente']->id);
        $this->warn('Guarde a chave agora. Ela nao sera mostrada de novo:');
        $this->line($resultado['chave']);

        return self::SUCCESS;
    }
}
