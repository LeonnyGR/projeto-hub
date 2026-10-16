<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\PapelUsuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\UsuarioRequest;
use App\Http\Resources\UsuarioResource;
use App\Services\PermissaoService;
use App\Services\UsuarioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Usuarios do cliente: o dono (adm_tenant) cadastra e gerencia os operadores. */
class UsuarioController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return UsuarioResource::collection($this->cliente($request)->usuarios()->with('permissoes')->orderBy('nome')->get());
    }

    /** Cria o operador. "permissoes" (opcional) ja libera as aplicacoes na criacao; sem ela o operador nao ve nada. */
    public function store(UsuarioRequest $request, UsuarioService $service, PermissaoService $permissoes): JsonResponse
    {
        $dados = $request->validated();

        $operador = DB::transaction(function () use ($dados, $request, $service, $permissoes) {
            $operador = $service->criarOperador($this->cliente($request), $dados);
            $permissoes->definir($operador, $dados['permissoes'] ?? [], $request->attributes->get('usuario'));

            return $operador;
        });
        $operador->load('permissoes');

        return (new UsuarioResource($operador))->response()->setStatusCode(201);
    }

    /** So operadores do proprio cliente: o dono (e usuarios de outro cliente) nao sao encontrados (404). */
    public function update(UsuarioRequest $request, string $id, UsuarioService $service): UsuarioResource
    {
        $operador = $this->cliente($request)->usuarios()->where('papel', PapelUsuario::OperadorTenant)->findOrFail($id);

        return new UsuarioResource($service->atualizarOperador($operador, $request->validated()));
    }
}
