<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\PermissaoService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "permite:OPE_02,etiqueta": libera so quem pode essa acao nessa aplicacao (os outros recebem 403 sem_permissao).
 * So vale para o portal: quem chama com chave de API (sem 'usuario') passa direto, o escopo dela e outro assunto.
 * O dono (adm_tenant) pode tudo.
 */
class PermiteAcao
{
    public function __construct(private readonly PermissaoService $permissoes)
    {
    }

    public function handle(Request $request, Closure $next, string $aplicacao, string $acao): Response
    {
        $usuario = $request->attributes->get('usuario');

        if ($usuario !== null && ! $this->permissoes->pode($usuario, $aplicacao, $acao)) {
            return response()->json([
                'erro' => ['codigo' => 'sem_permissao', 'mensagem' => 'Voce nao tem permissao para esta acao.', 'detalhes' => ['aplicacao' => $aplicacao, 'acao' => $acao]],
                'request_id' => RequestId::de($request),
            ], 403);
        }

        return $next($request);
    }
}
