<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ClienteApi;
use Illuminate\Console\Command;

class ListarClientesApi extends Command
{
    protected $signature = 'cliente:list';

    protected $description = 'Lista os clientes da API e quantas chaves ativas cada um tem (as chaves em si nao sao mostradas)';

    public function handle(): int
    {
        $linhas = ClienteApi::orderBy('nome')->get()->map(fn (ClienteApi $c) => [
            $c->id, $c->nome, $c->ativo ? 'sim' : 'nao', $c->chaves()->ativas()->count(),
        ]);

        $this->table(['ID', 'Nome', 'Ativo', 'Chaves ativas'], $linhas->all());

        return self::SUCCESS;
    }
}
