<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ErroDeNegocio;
use App\Http\Requests\TransportadoraRequest;
use App\Http\Resources\TransportadoraResource;
use App\Services\TransportadoraService;
use App\Transportadoras\Transportadoras;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Transportadoras do cliente que esta chamando: consulta e cadastro. Nunca mostra segredo (token, senha).
 * O comando transportadora:configurar faz o mesmo cadastro pelo terminal.
 */
class TransportadoraController extends Controller
{
    /** Quais transportadoras o hub atende e os campos de cadastro de cada uma (para montar o formulario). */
    public function catalogo(): JsonResponse
    {
        return response()->json(['data' => Transportadoras::catalogo()]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return TransportadoraResource::collection($this->cliente($request)->credenciais()->orderBy('transportadora')->get());
    }

    /** Uma transportadora do cliente (para preencher o formulario de edicao). Credenciais so mascaradas. */
    public function show(Request $request, string $transportadora): TransportadoraResource
    {
        return new TransportadoraResource(
            $this->cliente($request)->credenciais()->where('transportadora', $transportadora)->first()
                ?? throw new ErroDeNegocio('transportadora_nao_cadastrada', 'Esta transportadora ainda nao foi cadastrada.', 404)
        );
    }

    /** Cadastra ou edita o contrato com a transportadora: 201 se era novo, 200 se atualizou. */
    public function update(TransportadoraRequest $request, string $transportadora, TransportadoraService $service): JsonResponse
    {
        $existia = $request->jaCadastrada();
        $credencial = $service->configurar($this->cliente($request), $transportadora, $request->validated());

        return (new TransportadoraResource($credencial))->response()->setStatusCode($existia ? 200 : 201);
    }

    /** Liga ou desliga a transportadora (desligada nao entra nas cotacoes). Os dados continuam salvos. */
    public function ativar(TransportadoraRequest $request, string $transportadora): TransportadoraResource
    {
        $credencial = $this->cliente($request)->credenciais()->where('transportadora', $transportadora)->first()
            ?? throw new ErroDeNegocio('transportadora_nao_cadastrada', 'Esta transportadora ainda nao foi cadastrada.', 404);

        $credencial->update(['ativa' => $request->validated('ativa')]);

        return new TransportadoraResource($credencial);
    }
}
