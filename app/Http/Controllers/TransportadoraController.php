<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\TransportadoraResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Transportadoras cadastradas para o cliente da API (B2C): so consulta, e nunca mostra segredo.
 * Quem cadastra ou troca credenciais e a TI, pelo comando transportadora:configurar.
 */
class TransportadoraController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return TransportadoraResource::collection($this->cliente($request)->credenciais()->orderBy('transportadora')->get());
    }
}
