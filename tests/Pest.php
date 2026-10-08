<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as RespostaGuzzle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

// Testes de Feature usam o app inteiro e desfazem as alteracoes no banco ao fim de cada teste.
pest()->extend(TestCase::class)->use(DatabaseTransactions::class)->in('Feature');

/** Resposta HTTP pronta para usar em testes das transportadoras. */
function respostaJson(array|string $corpo, int $status = 200): Response
{
    return new Response(new RespostaGuzzle($status, ['Content-Type' => 'application/json'], is_array($corpo) ? json_encode($corpo) : $corpo));
}

/**
 * Entra no portal pelo fluxo real (POST /portal/login). Se der certo, guarda o cookie de sessao
 * para as proximas chamadas do teste, como o navegador faria. Devolve a resposta do login.
 */
function entrarNoPortal($teste, \App\Models\Usuario|string $quem, string $senha = 'senha-de-teste-123'): \Illuminate\Testing\TestResponse
{
    $email = is_string($quem) ? $quem : $quem->email;
    $resposta = $teste->postJson('/api/v1/portal/login', ['email' => $email, 'password' => $senha]);

    if ($resposta->status() === 200) {
        $cookie = collect($resposta->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $teste->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    esquecerAutenticacao($teste);

    return $resposta;
}

/** Cada requisicao real comeca sem usuario em memoria; nos testes o app e reaproveitado, entao limpamos. */
function esquecerAutenticacao($teste): void
{
    app('auth')->forgetGuards();
}
