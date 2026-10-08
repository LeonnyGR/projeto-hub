<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CotacaoRequest;
use App\Http\Resources\CotacaoResource;
use App\Services\CotacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CotacaoController extends Controller
{
    public function store(CotacaoRequest $request, CotacaoService $service): JsonResponse
    {
        $cotacao = $service->cotar($this->cliente($request), $request->validated());

        return (new CotacaoResource($cotacao))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $id): CotacaoResource
    {
        // Parte sempre do cliente logado: cotacao de outro cliente da 404.
        return new CotacaoResource($this->cliente($request)->cotacoes()->findOrFail($id));
    }
}
