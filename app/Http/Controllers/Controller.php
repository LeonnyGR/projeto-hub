<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ClienteApi;
use App\Models\Seller;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Cliente da API (B2C) autenticado pela chave. */
    protected function cliente(Request $request): ClienteApi
    {
        return $request->attributes->get('cliente_api');
    }

    /** Seller da URL (/sellers/{seller}/...), ja carregado pelo middleware CarregaSeller. */
    protected function seller(Request $request): Seller
    {
        return $request->attributes->get('seller');
    }
}
