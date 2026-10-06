<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ApiKeyService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica o sistema que chama a API (o B2C) pela chave de API,
 * enviada no header "Authorization: Bearer prefixo.segredo".
 * Se a chave for valida, deixa o cliente em $request->attributes ('cliente_api').
 * Qualquer falha devolve o mesmo 401 (nao revela se a chave existe).
 * Muitas falhas seguidas do mesmo IP bloqueiam esse IP por um tempo (429).
 */
class AutenticaCliente
{
    public function __construct(private readonly ApiKeyService $chaves)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $limite = 'auth-falha:'.$request->ip();

        if (RateLimiter::tooManyAttempts($limite, (int) config('frete.limites.auth_falhas_ip'))) {
            throw new ThrottleRequestsException('', null, ['Retry-After' => (string) RateLimiter::availableIn($limite)]);
        }

        $cliente = $this->chaves->autenticar($request->bearerToken());

        if ($cliente === null) {
            RateLimiter::hit($limite, (int) config('frete.limites.auth_falhas_janela'));

            throw new AuthenticationException();
        }

        $request->attributes->set('cliente_api', $cliente);
        Context::add('cliente_api_id', $cliente->id); // aparece em todo log desta requisicao

        return $next($request);
    }
}
