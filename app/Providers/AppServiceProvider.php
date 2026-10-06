<?php

declare(strict_types=1);

namespace App\Providers;

use App\Transportadoras\Mock\SimuladorHttp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->definirLimitesDeRequisicao();
        $this->prepararModoDasTransportadoras();
    }

    /**
     * Limites por minuto. Cada seller tem o proprio contador (um seller nao atrapalha o outro).
     * Rotas sem seller (CEP, cadastro) contam por cliente da API.
     * Os valores ficam em config/frete.php.
     */
    private function definirLimitesDeRequisicao(): void
    {
        $quem = fn (Request $r) => $r->attributes->get('seller')?->id ?? $r->attributes->get('cliente_api')?->id ?? $r->ip();

        RateLimiter::for('cotacoes', fn (Request $r) => Limit::perMinute((int) config('frete.limites.cotacoes'))->by('cotacoes:'.$quem($r)));
        RateLimiter::for('remessas', fn (Request $r) => Limit::perMinute((int) config('frete.limites.remessas'))->by('remessas:'.$quem($r)));
        RateLimiter::for('cadastros', fn (Request $r) => Limit::perMinute((int) config('frete.limites.cadastros'))->by('cadastros:'.$quem($r)));
        RateLimiter::for('consultas', fn (Request $r) => Limit::perMinute((int) config('frete.limites.consultas'))->by('consultas:'.$quem($r)));
    }

    /** mock = respostas simuladas (nunca em producao). real = chama as transportadoras. */
    private function prepararModoDasTransportadoras(): void
    {
        $modo = config('transportadoras.modo');

        if (! in_array($modo, ['mock', 'real'], true)) {
            throw new RuntimeException("TRANSPORTADORAS_MODO invalido: '{$modo}'. Use 'mock' ou 'real'.");
        }

        if ($modo === 'mock') {
            if ($this->app->environment('production')) {
                throw new RuntimeException('TRANSPORTADORAS_MODO=mock nao pode ser usado com APP_ENV=production.');
            }

            SimuladorHttp::instalar();
        }
    }
}
