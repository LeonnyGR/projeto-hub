<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\TrocaSenhaRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\Usuario;
use App\Services\PermissaoService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Login e logout do portal. O login cria uma sessao no servidor e entrega ao navegador um cookie
 * HttpOnly + SameSite (config/session.php): o JavaScript da pagina nao consegue ler o cookie.
 */
class SessaoController extends Controller
{
    /** Hash de uma senha qualquer: serve para o tempo de resposta ser igual quando o e-mail nao existe. */
    private const HASH_FALSO = '$2y$12$YgpPVHzId8XjetvJfTtHV.If23/rBEvzKz/qbqhgatZrOlM4qCDw2';

    public function login(LoginRequest $request): UsuarioResource
    {
        $usuario = Usuario::where('email', $request->validated('email'))->first();

        $senhaConfere = Hash::check($request->validated('password'), $usuario?->password ?? self::HASH_FALSO);

        // Mesmo erro (401) para e-mail inexistente, senha errada, usuario bloqueado ou cliente desabilitado.
        if ($usuario === null || ! $senhaConfere || ! $usuario->ativo || ! $usuario->cliente->ativo) {
            throw new AuthenticationException();
        }

        Auth::guard('portal')->login($usuario);
        $request->session()->regenerate(); // sessao nova a cada login (evita "session fixation")
        $usuario->forceFill(['ultimo_login_em' => now()])->save();

        return new UsuarioResource($usuario);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /** Quem esta logado, de qual cliente e o que pode ver e fazer (menu). */
    public function eu(Request $request, PermissaoService $permissoes): JsonResponse
    {
        $usuario = $request->attributes->get('usuario');

        return response()->json(['data' => (new UsuarioResource($usuario))->toArray($request) + [
            'cliente' => ['id' => $usuario->cliente->id, 'nome' => $usuario->cliente->nome, 'cnpj' => $usuario->cliente->cnpj],
            // Menu ja filtrado: so os modulos e aplicacoes que a pessoa pode usar, com as acoes de cada uma.
            'menu' => $permissoes->menu($usuario),
        ]]);
    }

    public function trocarSenha(TrocaSenhaRequest $request): Response
    {
        $usuario = $request->attributes->get('usuario');

        if (! Hash::check($request->validated('senha_atual'), $usuario->password)) {
            throw new AuthenticationException();
        }

        $usuario->update(['password' => $request->validated('nova_senha')]);

        return response()->noContent();
    }
}
