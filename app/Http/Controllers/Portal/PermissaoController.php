<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\PapelUsuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\PermissoesRequest;
use App\Services\CatalogoDePermissoes;
use App\Services\PermissaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** O que cada operador pode ver e fazer no portal. Quem tem ADM_02 (o dono sempre) gerencia. */
class PermissaoController extends Controller
{
    /** Modulos e aplicacoes com as acoes possiveis: serve para montar a tela de marcar permissoes. */
    public function catalogo(CatalogoDePermissoes $catalogo): JsonResponse
    {
        return response()->json(['data' => $catalogo->arvore()]);
    }

    public function show(Request $request, string $id, PermissaoService $permissoes): JsonResponse
    {
        $operador = $this->operador($request, $id);

        return response()->json(['data' => ['usuario_id' => $operador->id, 'permissoes' => $permissoes->doOperador($operador)]]);
    }

    /** Troca todas as permissoes do operador pelas enviadas. */
    public function update(PermissoesRequest $request, string $id, PermissaoService $permissoes): JsonResponse
    {
        $operador = $this->operador($request, $id);
        $permissoes->definir($operador, $request->validated('permissoes'), $request->attributes->get('usuario'));

        return response()->json(['data' => ['usuario_id' => $operador->id, 'permissoes' => $permissoes->doOperador($operador)]]);
    }

    /** So operadores do proprio cliente: o dono (pode tudo sempre) e usuarios de outro cliente dao 404. */
    private function operador(Request $request, string $id)
    {
        return $this->cliente($request)->usuarios()->where('papel', PapelUsuario::OperadorTenant)->findOrFail($id);
    }
}
