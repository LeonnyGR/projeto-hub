<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CatalogoDePermissoes;
use Illuminate\Console\Command;

class SincronizarPermissoes extends Command
{
    protected $signature = 'permissoes:sincronizar';

    protected $description = 'Leva os modulos e aplicacoes de config/permissoes.php para o banco (rode depois de mexer nesse arquivo)';

    public function handle(CatalogoDePermissoes $catalogo): int
    {
        $catalogo->sincronizar();
        $this->info('Catalogo de permissoes sincronizado.');

        return self::SUCCESS;
    }
}
