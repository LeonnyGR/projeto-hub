<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DevolucaoRequest;
use App\Http\Resources\RemessaResource;
use App\Models\Remessa;
use App\Services\RemessaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DevolucaoController extends Controller
{
    public function store(DevolucaoRequest $request, RemessaService $service): JsonResponse
    {
        [$devolucao, $jaExistia] = $service->criarDevolucao($this->seller($request), $request->validated());

        return (new RemessaResource($devolucao))->response()
            ->setStatusCode($jaExistia ? 200 : 201)
            ->header('Idempotent-Replayed', $jaExistia ? 'true' : 'false');
    }

    public function show(Request $request, string $id): RemessaResource
    {
        return new RemessaResource($this->buscar($request, $id));
    }

    public function etiqueta(Request $request, string $id, RemessaService $service): Response
    {
        $pdf = $service->etiqueta($this->seller($request), $this->buscar($request, $id));

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"etiqueta-devolucao-{$id}.pdf\""]);
    }

    private function buscar(Request $request, string $id): Remessa
    {
        return $this->seller($request)->remessas()->where('tipo', 'devolucao')->findOrFail($id);
    }
}
