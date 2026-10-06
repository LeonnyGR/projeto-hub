<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carrega o seller informado na URL (/sellers/{seller}/...) e o deixa em $request->attributes ('seller').
 * Seller que nao existe da 404. Todas as consultas das rotas partem desse seller
 * ($seller->remessas()->findOrFail($id)): remessa de outro seller tambem da 404.
 */
class CarregaSeller
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->route('seller');

        // Id que nao e UUID nao pode chegar no banco (o Postgres daria erro): e 404 igual a um seller que nao existe.
        // So enxerga sellers do proprio cliente da API: seller de outro cliente tambem e 404.
        $seller = Str::isUuid($id) ? $request->attributes->get('cliente_api')->sellers()->find($id) : null;

        if ($seller === null) {
            throw new ModelNotFoundException();
        }

        // O Laravel entrega os parametros da URL ao controller pela ORDEM. O seller ja foi carregado aqui,
        // entao o tiramos da lista: assim os controllers recebem so o que e deles ({id}, {transportadora}...).
        $request->route()->forgetParameter('seller');

        $request->attributes->set('seller', $seller);
        Context::add('seller_id', $seller->id);

        return $next($request);
    }
}
