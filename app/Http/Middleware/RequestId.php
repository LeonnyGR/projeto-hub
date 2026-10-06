<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Da um request_id para cada requisicao. Ele aparece na resposta (header X-Request-Id e nos erros)
 * e em todos os logs, para achar o que aconteceu a partir do que o cliente viu.
 * O nginx envia o id dele (32 caracteres hex); qualquer outro valor vindo do cliente e ignorado.
 */
class RequestId
{
    public const ATRIBUTO = 'request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $recebido = (string) $request->headers->get('X-Request-Id', '');
        $id = preg_match('/^[a-f0-9]{32}$/', $recebido) === 1 ? $recebido : str_replace('-', '', (string) Str::uuid());

        $request->attributes->set(self::ATRIBUTO, $id);
        Context::add('request_id', $id); // entra automaticamente em todo log

        $resposta = $next($request);
        $resposta->headers->set('X-Request-Id', $id);

        return $resposta;
    }

    public static function de(Request $request): string
    {
        return (string) $request->attributes->get(self::ATRIBUTO, '');
    }
}
