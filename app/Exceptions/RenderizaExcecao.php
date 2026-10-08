<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Middleware\RequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Todo erro da API sai neste formato:
 *   {"erro": {"codigo": "...", "mensagem": "...", "detalhes": {...}}, "request_id": "..."}
 * Erros inesperados viram um 500 generico (sem stack trace, SQL ou detalhes internos).
 */
class RenderizaExcecao
{
    public function __invoke(Throwable $e, Request $request): JsonResponse
    {
        [$status, $codigo, $mensagem, $detalhes, $headers] = $this->classifica($e);

        $erro = ['codigo' => $codigo, 'mensagem' => $mensagem];
        if ($detalhes !== []) {
            $erro['detalhes'] = $detalhes;
        }

        return response()->json(
            ['erro' => $erro, 'request_id' => RequestId::de($request)],
            $status,
            $headers,
        );
    }

    /** Devolve [status, codigo, mensagem, detalhes, headers]. */
    private function classifica(Throwable $e): array
    {
        if ($e instanceof ErroDeNegocio) {
            return [$e->statusHttp(), $e->codigoErro(), $e->getMessage(), $e->detalhes(), []];
        }

        if ($e instanceof ValidationException) {
            return [422, 'validacao_falhou', 'Os dados informados sao invalidos.', $e->errors(), []];
        }

        if ($e instanceof AuthenticationException) {
            return [401, 'nao_autenticado', 'Credenciais ausentes ou invalidas.', [], ['WWW-Authenticate' => 'Bearer realm="frete-hub"']];
        }

        // O Laravel troca AuthorizationException por AccessDeniedHttpException antes de chegar aqui; tratamos as duas.
        if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
            return [403, 'proibido', 'Acao nao permitida.', [], []];
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return [404, 'nao_encontrado', 'Recurso nao encontrado.', [], []];
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return [405, 'metodo_nao_permitido', 'Metodo nao permitido.', [], $this->headersPermitidos($e, ['Allow'])];
        }

        if ($e instanceof ThrottleRequestsException) {
            $permitidos = ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'];

            return [429, 'muitas_requisicoes', 'Muitas requisicoes. Tente novamente mais tarde.', [], $this->headersPermitidos($e, $permitidos)];
        }

        if ($e instanceof HttpExceptionInterface) {
            return match ($e->getStatusCode()) {
                400 => [400, 'requisicao_invalida', 'Requisicao invalida.', [], []],
                413 => [413, 'payload_muito_grande', 'Corpo da requisicao excede o limite.', [], []],
                415 => [415, 'tipo_nao_suportado', 'Content-Type nao suportado.', [], []],
                default => $e->getStatusCode() >= 500
                    ? [$e->getStatusCode(), 'erro_interno', 'Erro interno. Informe o request_id ao suporte.', [], []]
                    : [$e->getStatusCode(), 'erro_http', 'Requisicao nao atendida.', [], []],
            };
        }

        return [500, 'erro_interno', 'Erro interno. Informe o request_id ao suporte.', [], []];
    }

    /** Copia so os headers da lista (nao repassa nada alem do necessario). */
    private function headersPermitidos(HttpExceptionInterface $e, array $permitidos): array
    {
        $saida = [];
        foreach ($e->getHeaders() as $nome => $valor) {
            if (in_array($nome, $permitidos, true)) {
                $saida[$nome] = is_array($valor) ? implode(', ', $valor) : (string) $valor;
            }
        }

        return $saida;
    }
}
