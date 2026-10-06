<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Sem seeds no MVP: a chave do B2C e criada por `cliente:create`; sellers e contratos, pela API.
    }
}
