<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StatusRemessa;
use App\Models\Cliente;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Lista paginada (mais novas primeiro) das remessas do cliente de um tipo (envio | devolucao). Filtro opcional: ?status= */
    protected function remessasDoCliente(Request $request, string $tipo): LengthAwarePaginator
    {
        $request->validate(['status' => ['sometimes', Rule::enum(StatusRemessa::class)]]);

        return $this->cliente($request)->remessas()->where('tipo', $tipo)
            ->when($request->query('status'), fn ($consulta, $status) => $consulta->where('status', $status))
            ->latest()->paginate(50);
    }

    /** Cliente que esta chamando a API (identificado pela chave de API ou pelo login do portal). */
    protected function cliente(Request $request): Cliente
    {
        return $request->attributes->get('cliente');
    }
}
