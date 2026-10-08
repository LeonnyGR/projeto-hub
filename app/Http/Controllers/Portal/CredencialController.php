<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Exceptions\ErroDeNegocio;
use App\Http\Controllers\Controller;
use App\Http\Requests\RevelarCredenciaisRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/** Mostrar ao dono, por inteiro, as credenciais que ele mesmo cadastrou numa transportadora. */
class CredencialController extends Controller
{
    /**
     * Pede a senha do dono de novo (sessao esquecida aberta nao basta) e devolve as credenciais por inteiro.
     * Cada consulta fica registrada no log (quem, quando, qual transportadora) e tem limite de tentativas.
     */
    public function revelar(RevelarCredenciaisRequest $request, string $transportadora): JsonResponse
    {
        $usuario = $request->attributes->get('usuario');

        if (! Hash::check($request->validated('password'), $usuario->password)) {
            throw new ErroDeNegocio('senha_incorreta', 'Senha incorreta.', 422);
        }

        $credencial = $this->cliente($request)->credenciais()->where('transportadora', $transportadora)->first()
            ?? throw new ErroDeNegocio('transportadora_nao_cadastrada', 'Esta transportadora ainda nao foi cadastrada.', 404);

        Log::notice('Credenciais de transportadora reveladas', [
            'cliente_id' => $usuario->cliente_id,
            'usuario_id' => $usuario->id,
            'transportadora' => $transportadora,
        ]);

        return response()->json(['data' => ['transportadora' => $transportadora, 'credenciais' => $credencial->credenciais]]);
    }
}
