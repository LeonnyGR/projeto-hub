<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Transportadoras\Transportadoras;

class AtivarTransportadora extends ComandoDeCliente
{
    protected $signature = 'transportadora:ativar {transportadora : Codigo da transportadora, o mesmo usado na API} {cliente? : ID do cliente da API} {--desativar : Desativa em vez de ativar}';

    protected $description = 'Ativa ou desativa uma transportadora ja cadastrada, sem mexer nas credenciais. Desativada, ela some das cotacoes de todos os sellers';

    public function handle(): int
    {
        $codigo = (string) $this->argument('transportadora');

        if (! in_array($codigo, Transportadoras::codigos(), true)) {
            $this->error('Transportadora desconhecida. Use: '.implode(', ', Transportadoras::codigos()));

            return self::FAILURE;
        }

        $cliente = $this->cliente();
        if ($cliente === null) {
            return self::FAILURE;
        }

        $credencial = $cliente->credenciais()->where('transportadora', $codigo)->first();
        if ($credencial === null) {
            $this->error("'{$codigo}' ainda nao foi cadastrada. Use: transportadora:configurar {$codigo}");

            return self::FAILURE;
        }

        $ativar = ! $this->option('desativar');
        $credencial->update(['ativa' => $ativar]);

        $this->info("Transportadora '{$codigo}' ".($ativar ? 'ativada.' : 'desativada.'));

        return self::SUCCESS;
    }
}
