<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\PapelUsuario;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usado depois de auth.portal: "papel:adm_tenant" libera so o dono do cliente (os outros recebem 403). */
class ExigePapel
{
    public function handle(Request $request, Closure $next, string $papel): Response
    {
        if ($request->attributes->get('usuario')?->papel !== PapelUsuario::from($papel)) {
            throw new AuthorizationException();
        }

        return $next($request);
    }
}
