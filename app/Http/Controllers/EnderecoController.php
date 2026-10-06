<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ErroDeNegocio;
use App\Services\ConsultasService;
use Illuminate\Http\JsonResponse;

class EnderecoController extends Controller
{
    public function show(string $cep, ConsultasService $consultas): JsonResponse
    {
        $cep = preg_replace('/\D/', '', $cep);

        if (strlen($cep) !== 8) {
            throw new ErroDeNegocio('cep_invalido', 'O CEP deve ter 8 digitos.', 422);
        }

        return response()->json(['data' => $consultas->endereco($cep)]);
    }
}
