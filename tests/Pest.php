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
