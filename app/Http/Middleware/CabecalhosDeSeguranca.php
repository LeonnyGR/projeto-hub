<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CabecalhosDeSeguranca
{
    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);
        $h = $resposta->headers;

        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'no-referrer');
        $h->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        // Nenhuma resposta da API deve ficar em cache.
        $h->set('Cache-Control', 'no-store');

        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $h->remove('X-Powered-By');

        return $resposta;
    }
}
