<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PontoRetiradaRequest;
use App\Services\ConsultasService;
use Illuminate\Http\JsonResponse;

class PontoRetiradaController extends Controller
{
    public function index(PontoRetiradaRequest $request, ConsultasService $consultas): JsonResponse
    {
        [$pontos, $falhas] = $consultas->pontosDeRetirada(
            $this->seller($request),
            $request->validated('cep'),
            $request->validated('tipos'),
            $request->validated('transportadora'),
        );

        // "falhas": transportadoras que nao responderam (as demais continuam), como na cotacao.
        return response()->json(['data' => $pontos, 'falhas' => $falhas]);
    }
}
