<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ChaveApiService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Descobre qual cliente esta chamando a API pela chave de API:
 * "Authorization: Bearer <chave>" (a chave e criada pelo dono do cliente no portal).
 * Se a chave for valida, deixa o cliente em $request->attributes ('cliente'): dai em diante tudo e feito "como"
 * esse cliente, com os dados e as credenciais de transportadora dele.
 * Qualquer falha devolve o mesmo 401 (nao revela se a chave existe).
 * Muitas falhas seguidas do mesmo IP bloqueiam esse IP por um tempo (429).
 */
class AutenticaCliente
{
    public function __construct(private readonly ChaveApiService $chaves)
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

        $request->attributes->set('cliente', $cliente);
        Context::add('cliente_id', $cliente->id); // aparece em todo log desta requisicao

        return $next($request);
    }
}
