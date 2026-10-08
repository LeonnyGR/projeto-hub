<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege as rotas de administracao (/admin/...), usadas por nos para cadastrar clientes.
 * Quem chama manda o token de admin: "Authorization: Bearer <ADMIN_API_TOKEN>". O token fica so no .env do servidor.
 * Sem ADMIN_API_TOKEN configurado (ou curto demais), a administracao fica desligada: tudo da 401.
 * Qualquer falha devolve o mesmo 401; muitas falhas seguidas do mesmo IP bloqueiam esse IP por um tempo (429).
 */
class AutenticaAdmin
{
    private const TAMANHO_MINIMO = 32;

    public function handle(Request $request, Closure $next): Response
    {
        $limite = 'auth-falha:'.$request->ip();

        if (RateLimiter::tooManyAttempts($limite, (int) config('frete.limites.auth_falhas_ip'))) {
            throw new ThrottleRequestsException('', null, ['Retry-After' => (string) RateLimiter::availableIn($limite)]);
        }

        $esperado = (string) config('frete.admin_token');
        $enviado = (string) $request->bearerToken();

        if (strlen($esperado) < self::TAMANHO_MINIMO || ! hash_equals($esperado, $enviado)) {
            RateLimiter::hit($limite, (int) config('frete.limites.auth_falhas_janela'));

            throw new AuthenticationException();
        }

        return $next($request);
    }
}
