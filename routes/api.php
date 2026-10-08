<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ClienteController;
use App\Http\Controllers\ContaController;
use App\Http\Controllers\CotacaoController;
use App\Http\Controllers\DevolucaoController;
use App\Http\Controllers\EnderecoController;
use App\Http\Controllers\Portal\ChaveApiController;
use App\Http\Controllers\Portal\CredencialController;
use App\Http\Controllers\Portal\SessaoController;
use App\Http\Controllers\Portal\UsuarioController;
use App\Http\Controllers\PontoRetiradaController;
use App\Http\Controllers\RemessaController;
use App\Http\Controllers\TransportadoraController;
use App\Transportadoras\Transportadoras;
use Illuminate\Support\Facades\Route;

// Existem tres "portas de entrada", cada uma com a sua autenticacao:
//   1. /api/v1/admin/...  so para nos (administradores): token de admin (ADMIN_API_TOKEN).
//   2. /api/v1/...        o backend do cliente (ex.: o B2C): chave de API ("Authorization: Bearer <chave>").
//   3. /api/v1/portal/... as pessoas do cliente (dono e operadores): login com e-mail e senha (cookie de sessao).
// As portas 2 e 3 usam as MESMAS rotas de negocio (cotacoes, remessas...), definidas uma vez em $rotasDoDiaADia
// e $rotasDeConfiguracao; muda so quem esta autenticado. Em todas, o cliente e sempre o dono dos dados.

// ---------------------------------------------------------------- 1. administracao
Route::prefix('admin')->middleware(['auth.admin', 'throttle:cadastros'])->group(function () {
    Route::get('clientes', [ClienteController::class, 'index']);
    Route::post('clientes', [ClienteController::class, 'store']);
    Route::get('clientes/{id}', [ClienteController::class, 'show'])->whereUuid('id');
    Route::patch('clientes/{id}', [ClienteController::class, 'update'])->whereUuid('id');
});

// ---------------------------------------------------------------- rotas de negocio (portas 2 e 3)

// Dia a dia: consultar, cotar, criar remessas e imprimir etiquetas. Dono e operador usam.
$rotasDoDiaADia = function () {
    Route::get('conta', [ContaController::class, 'show'])->middleware('throttle:cadastros');
    Route::get('transportadoras', [TransportadoraController::class, 'index'])->middleware('throttle:cadastros');
    Route::get('transportadoras/catalogo', [TransportadoraController::class, 'catalogo'])->middleware('throttle:cadastros');
    Route::get('transportadoras/{transportadora}', [TransportadoraController::class, 'show'])
        ->whereIn('transportadora', Transportadoras::codigos())->middleware('throttle:cadastros');

    // Consultas
    Route::get('enderecos/{cep}', [EnderecoController::class, 'show'])->middleware('throttle:consultas');
    Route::get('pontos-retirada', [PontoRetiradaController::class, 'index'])->middleware('throttle:consultas');

    // Cotacao
    Route::post('cotacoes', [CotacaoController::class, 'store'])->middleware('throttle:cotacoes');
    Route::get('cotacoes/{id}', [CotacaoController::class, 'show'])->whereUuid('id')->middleware('throttle:consultas');

    // Remessas (envios). Criar exige o header Idempotency-Key.
    Route::get('remessas', [RemessaController::class, 'index'])->middleware('throttle:consultas');
    Route::post('remessas', [RemessaController::class, 'store'])->middleware('throttle:remessas');
    Route::get('remessas/{id}', [RemessaController::class, 'show'])->whereUuid('id')->middleware('throttle:consultas');
    Route::post('remessas/{id}/despachar', [RemessaController::class, 'despachar'])->whereUuid('id')->middleware('throttle:remessas');
    Route::post('remessas/{id}/cancelar', [RemessaController::class, 'cancelar'])->whereUuid('id')->middleware('throttle:remessas');
    Route::get('remessas/{id}/etiqueta', [RemessaController::class, 'etiqueta'])->whereUuid('id')->middleware('throttle:consultas');

    // Devolucoes (uma remessa no sentido contrario, de um envio ja entregue)
    Route::get('devolucoes', [DevolucaoController::class, 'index'])->middleware('throttle:consultas');
    Route::post('devolucoes', [DevolucaoController::class, 'store'])->middleware('throttle:remessas');
    Route::get('devolucoes/{id}', [DevolucaoController::class, 'show'])->whereUuid('id')->middleware('throttle:consultas');
    Route::get('devolucoes/{id}/etiqueta', [DevolucaoController::class, 'etiqueta'])->whereUuid('id')->middleware('throttle:consultas');
};

// Configuracao: cadastrar o contrato com as transportadoras (as respostas nunca trazem segredo).
// No portal, so o dono faz isso. (O comando transportadora:configurar faz o mesmo pelo terminal.)
$rotasDeConfiguracao = function () {
    Route::put('transportadoras/{transportadora}', [TransportadoraController::class, 'update'])
        ->whereIn('transportadora', Transportadoras::codigos())->middleware('throttle:cadastros');
    Route::patch('transportadoras/{transportadora}', [TransportadoraController::class, 'ativar'])
        ->whereIn('transportadora', Transportadoras::codigos())->middleware('throttle:cadastros');
};

// ---------------------------------------------------------------- 2. chave de API
Route::middleware('auth.cliente')->group(function () use ($rotasDoDiaADia, $rotasDeConfiguracao) {
    $rotasDoDiaADia();
    $rotasDeConfiguracao();
});

// ---------------------------------------------------------------- 3. portal (cookie de sessao)
Route::prefix('portal')->middleware('portal')->group(function () use ($rotasDoDiaADia, $rotasDeConfiguracao) {
    // Sem login ainda. O limite protege contra tentativas de adivinhar a senha.
    Route::post('login', [SessaoController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth.portal')->group(function () use ($rotasDoDiaADia, $rotasDeConfiguracao) {
        Route::post('logout', [SessaoController::class, 'logout']);
        Route::get('eu', [SessaoController::class, 'eu']);
        Route::patch('eu/senha', [SessaoController::class, 'trocarSenha'])->middleware('throttle:login');

        $rotasDoDiaADia();

        // So o dono (adm_tenant): transportadoras, chaves de API e operadores.
        Route::middleware('papel:adm_tenant')->group(function () use ($rotasDeConfiguracao) {
            $rotasDeConfiguracao();

            // Ver as credenciais por inteiro (pede a senha do dono de novo)
            Route::post('transportadoras/{transportadora}/credenciais', [CredencialController::class, 'revelar'])
                ->whereIn('transportadora', Transportadoras::codigos())->middleware('throttle:revelar');

            Route::get('chaves', [ChaveApiController::class, 'index'])->middleware('throttle:cadastros');
            Route::post('chaves', [ChaveApiController::class, 'store'])->middleware('throttle:cadastros');
            Route::delete('chaves/{id}', [ChaveApiController::class, 'destroy'])->whereUuid('id')->middleware('throttle:cadastros');

            Route::get('usuarios', [UsuarioController::class, 'index'])->middleware('throttle:cadastros');
            Route::post('usuarios', [UsuarioController::class, 'store'])->middleware('throttle:cadastros');
            Route::patch('usuarios/{id}', [UsuarioController::class, 'update'])->whereUuid('id')->middleware('throttle:cadastros');
        });
    });
});
