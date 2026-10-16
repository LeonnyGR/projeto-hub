<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CotacaoRequest;
use App\Http\Resources\CotacaoResource;
use App\Services\CotacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CotacaoController extends Controller
{
    /** Lista as cotacoes do cliente (mais novas primeiro, 50 por pagina). */
    public function index(Request $request): AnonymousResourceCollection
    {
        return CotacaoResource::collection($this->cliente($request)->cotacoes()->latest()->paginate(50));
    }

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
