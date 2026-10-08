<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\PapelUsuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\UsuarioRequest;
use App\Http\Resources\UsuarioResource;
use App\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Usuarios do cliente: o dono (adm_tenant) cadastra e gerencia os operadores. */
class UsuarioController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return UsuarioResource::collection($this->cliente($request)->usuarios()->orderBy('nome')->get());
    }

    public function store(UsuarioRequest $request, UsuarioService $service): JsonResponse
    {
        $operador = $service->criarOperador($this->cliente($request), $request->validated());

        return (new UsuarioResource($operador))->response()->setStatusCode(201);
    }

    /** So operadores do proprio cliente: o dono (e usuarios de outro cliente) nao sao encontrados (404). */
    public function update(UsuarioRequest $request, string $id, UsuarioService $service): UsuarioResource
    {
        $operador = $this->cliente($request)->usuarios()->where('papel', PapelUsuario::OperadorTenant)->findOrFail($id);

        return new UsuarioResource($service->atualizarOperador($operador, $request->validated()));
    }
}
