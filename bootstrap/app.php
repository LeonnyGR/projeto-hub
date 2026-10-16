<?php

declare(strict_types=1);

use App\Exceptions\RenderizaExcecao;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AutenticaAdmin;
use App\Http\Middleware\AutenticaCliente;
use App\Http\Middleware\AutenticaPortal;
use App\Http\Middleware\CabecalhosDeSeguranca;
use App\Http\Middleware\ExigePapel;
use App\Http\Middleware\ForcaJson;
use App\Http\Middleware\PermiteAcao;
use App\Http\Middleware\RequestId;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            // Health check na raiz (o ALB nao usa o prefixo /api/v1).
            Route::get('/up', HealthController::class);
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // TRUSTED_PROXIES = IP/CIDR do ALB (separados por virgula). Vazio = nao confia em ninguem.
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
        $middleware->trustProxies(
            at: $proxies,
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_HOST,
        );

        $middleware->alias([
            'auth.cliente' => AutenticaCliente::class,
            'auth.admin' => AutenticaAdmin::class,
            'auth.portal' => AutenticaPortal::class,
            'papel' => ExigePapel::class,
            'permite' => PermiteAcao::class,
        ]);

        // Rotas do portal: sao as unicas com cookie e sessao (a API de chave de API nao usa cookie).
        $middleware->group('portal', [EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class]);
        $middleware->prepend([RequestId::class, ForcaJson::class]);
        $middleware->append(CabecalhosDeSeguranca::class);

        // Limite de requisicoes guardado no Redis. A autenticacao precisa rodar antes
        // (o limite e por cliente), por isso vai na frente do throttle.
        $middleware->throttleWithRedis();
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AutenticaCliente::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AutenticaAdmin::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AutenticaPortal::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->render(new RenderizaExcecao());

        // A mensagem de QueryException traz o SQL com os valores (dados pessoais). Logamos so o essencial.
        $exceptions->report(function (QueryException $e) {
            Log::error('Erro de banco de dados', ['sqlstate' => $e->getCode()]);
        })->stop();
    })->create();
