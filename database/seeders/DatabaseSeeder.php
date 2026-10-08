<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Sem seeds no MVP: clientes sao criados por `cliente:create`; transportadoras, pela API (ou `transportadora:configurar`).
    }
}
