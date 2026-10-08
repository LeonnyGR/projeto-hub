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
     * Limites por minuto. Cada cliente tem o proprio contador (um cliente nao atrapalha o outro).
     * Os valores ficam em config/frete.php.
     */
    private function definirLimitesDeRequisicao(): void
    {
        $quem = fn (Request $r) => $r->attributes->get('cliente')?->id ?? $r->ip();

        // Login do portal: por e-mail + IP (dificulta adivinhar senha sem bloquear outras pessoas do mesmo cliente).
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute((int) config('frete.limites.login'))->by('login:'.(strtolower((string) $r->input('email')) ?: (string) $r->attributes->get('usuario')?->id).'|'.$r->ip()));
        // Ver credenciais por inteiro: poucas por minuto, por usuario.
        RateLimiter::for('revelar', fn (Request $r) => Limit::perMinute((int) config('frete.limites.revelar'))->by('revelar:'.$r->attributes->get('usuario')?->id));
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
