<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RemessaRequest;
use App\Http\Resources\RemessaResource;
use App\Models\Remessa;
use App\Services\RemessaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RemessaController extends Controller
{
    public function store(RemessaRequest $request, RemessaService $service): JsonResponse
    {
        [$remessa, $jaExistia] = $service->criarEnvio($this->seller($request), $request->validated());

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
        return new RemessaResource($service->cancelar($this->seller($request), $this->buscar($request, $id)));
    }

    public function etiqueta(Request $request, string $id, RemessaService $service): Response
    {
        $pdf = $service->etiqueta($this->seller($request), $this->buscar($request, $id));

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"etiqueta-{$id}.pdf\""]);
    }

    /** Parte do seller logado: remessa de outro seller da 404. */
    private function buscar(Request $request, string $id): Remessa
    {
        return $this->seller($request)->remessas()->where('tipo', 'envio')->findOrFail($id);
    }
}
