<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ErroDeNegocio;
use App\Models\ClienteApi;
use App\Services\ApiKeyService;
use Illuminate\Console\Command;

class TrocarChaveDoCliente extends Command
{
    protected $signature = 'cliente:rotate-key {cliente : ID do cliente (veja cliente:list)}
        {--revoke-old : A chave atual deixa de valer agora}
        {--grace= : Horas que a chave atual continua valendo (padrao: 24)}';

    protected $description = 'Cria uma chave nova. A antiga continua valendo por um tempo (ate 2 chaves ativas)';

    public function handle(ApiKeyService $chaves): int
    {
        $cliente = ClienteApi::find((string) $this->argument('cliente'));

        if ($cliente === null) {
            $this->error('Cliente nao encontrado.');

            return self::FAILURE;
        }

        $horas = $this->option('grace');

        try {
            $chave = $chaves->trocarChave($cliente, (bool) $this->option('revoke-old'), $horas === null ? null : (int) $horas);
        } catch (ErroDeNegocio $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->warn('Nova chave (mostrada uma unica vez):');
        $this->line($chave);

        return self::SUCCESS;
    }
}
