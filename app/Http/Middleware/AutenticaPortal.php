<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica quem usa o portal pelo cookie de sessao (criado em POST /portal/login).
 * Deixa o usuario e o cliente dele em $request->attributes ('usuario' e 'cliente'): a partir dai os controllers
 * agem como se o cliente tivesse chamado a API com a chave dele.
 * Usuario bloqueado (ativo = false) ou de cliente desabilitado perde o acesso na hora, mesmo com a sessao aberta.
 */
class AutenticaPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = Auth::guard('portal')->user();

        if ($usuario === null || ! $usuario->ativo || ! $usuario->cliente->ativo) {
            throw new AuthenticationException();
        }

        $request->attributes->set('usuario', $usuario);
        $request->attributes->set('cliente', $usuario->cliente);
        Context::add('cliente_id', $usuario->cliente_id);
        Context::add('usuario_id', $usuario->id);

        return $next($request);
    }
}
