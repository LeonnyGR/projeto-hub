<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChaveApiRequest;
use App\Http\Resources\ChaveApiResource;
use App\Services\ChaveApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Chaves de API do cliente (so o dono mexe). */
class ChaveApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ChaveApiResource::collection($this->cliente($request)->chaves()->orderByDesc('created_at')->get());
    }

    /** Cria uma chave. O texto completo ("chave") aparece SO nesta resposta: copie e guarde (no banco fica so o hash). */
    public function store(ChaveApiRequest $request, ChaveApiService $service): JsonResponse
    {
        [$chave, $texto] = $service->criar($this->cliente($request), $request->validated('nome'));

        return response()->json(['data' => (new ChaveApiResource($chave))->toArray($request) + ['chave' => $texto]], 201);
    }

    /** Revoga a chave: quem a usa passa a receber 401. As outras chaves continuam funcionando. */
    public function destroy(Request $request, string $id, ChaveApiService $service): ChaveApiResource
    {
        $chave = $this->cliente($request)->chaves()->findOrFail($id);

        if ($chave->revogada_em === null) {
            $service->revogar($chave);
        }

        return new ChaveApiResource($chave);
    }
}
