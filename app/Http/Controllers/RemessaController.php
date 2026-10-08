<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RemessaRequest;
use App\Http\Resources\RemessaResource;
use App\Models\Remessa;
use App\Services\RemessaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RemessaController extends Controller
{
    /** Lista os envios do cliente (mais novos primeiro). Filtro opcional: ?status=criada */
    public function index(Request $request): AnonymousResourceCollection
    {
        return RemessaResource::collection($this->remessasDoCliente($request, 'envio'));
    }

    public function store(RemessaRequest $request, RemessaService $service): JsonResponse
    {
        [$remessa, $jaExistia] = $service->criarEnvio($this->cliente($request), $request->validated());

        // Repeticao da mesma chamada: devolve a remessa que ja existe (200), sem criar outra.
        return (new RemessaResource($remessa))->response()
            ->setStatusCode($jaExistia ? 200 : 201)
            ->header('Idempotent-Replayed', $jaExistia ? 'true' : 'false');
    }

    public function show(Request $request, string $id): RemessaResource
    {
        return new RemessaResource($this->buscar($request, $id));
    }

    public function despachar(Request $request, string $id, RemessaService $service): RemessaResource
    {
        return new RemessaResource($service->despachar($this->buscar($request, $id)));
    }

    public function cancelar(Request $request, string $id, RemessaService $service): RemessaResource
    {
        return new RemessaResource($service->cancelar($this->cliente($request), $this->buscar($request, $id)));
    }

    public function etiqueta(Request $request, string $id, RemessaService $service): Response
    {
        $pdf = $service->etiqueta($this->cliente($request), $this->buscar($request, $id));

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"etiqueta-{$id}.pdf\""]);
    }

    /** Parte do cliente logado: remessa de outro cliente da 404. */
    private function buscar(Request $request, string $id): Remessa
    {
        return $this->cliente($request)->remessas()->where('tipo', 'envio')->findOrFail($id);
    }
}
