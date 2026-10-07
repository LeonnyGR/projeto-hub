<?php

declare(strict_types=1);

use App\Http\Controllers\CotacaoController;
use App\Http\Controllers\DevolucaoController;
use App\Http\Controllers\EnderecoController;
use App\Http\Controllers\PontoRetiradaController;
use App\Http\Controllers\RemessaController;
use App\Http\Controllers\SellerController;
use App\Http\Controllers\TransportadoraController;
use App\Transportadoras\Transportadoras;
use Illuminate\Support\Facades\Route;

// Todas as rotas daqui ficam sob /api/v1 e exigem a chave de API do cliente (o B2C).
// Quase tudo e feito "em nome de um seller", informado na URL: /sellers/{seller}/...
Route::middleware('auth.cliente')->group(function () {
    // Consulta de CEP (nao depende de seller)
    Route::get('enderecos/{cep}', [EnderecoController::class, 'show'])->middleware('throttle:consultas');

    // Transportadoras do B2C: valem para todos os sellers. As respostas nunca trazem segredo (token, senha).
    // (O comando transportadora:configurar faz o mesmo cadastro pelo terminal.)
    Route::get('transportadoras', [TransportadoraController::class, 'index'])->middleware('throttle:cadastros');
    Route::put('transportadoras/{transportadora}', [TransportadoraController::class, 'update'])
        ->whereIn('transportadora', Transportadoras::codigos())->middleware('throttle:cadastros');
    Route::patch('transportadoras/{transportadora}', [TransportadoraController::class, 'ativar'])
        ->whereIn('transportadora', Transportadoras::codigos())->middleware('throttle:cadastros');

    // Cadastro de sellers
    Route::get('sellers', [SellerController::class, 'index'])->middleware('throttle:cadastros');
    Route::post('sellers', [SellerController::class, 'store'])->middleware('throttle:cadastros');

    // Tudo abaixo e de um seller especifico. O middleware carrega o seller (404 se nao existir).
    Route::prefix('sellers/{seller}')->whereUuid('seller')->middleware('seller.da.url')->group(function () {
        Route::get('/', [SellerController::class, 'show'])->middleware('throttle:cadastros');
        Route::patch('/', [SellerController::class, 'update'])->middleware('throttle:cadastros');

        // Consultas
        Route::get('pontos-retirada', [PontoRetiradaController::class, 'index'])->middleware('throttle:consultas');

        // Cotacao
        Route::post('cotacoes', [CotacaoController::class, 'store'])->middleware('throttle:cotacoes');
        Route::get('cotacoes/{id}', [CotacaoController::class, 'show'])->whereUuid('id')->middleware('throttle:consultas');

        // Remessas (envios). Criar exige o header Idempotency-Key.
        Route::post('remessas', [RemessaController::class, 'store'])->middleware('throttle:remessas');
        Route::get('remessas/{id}', [RemessaController::class, 'show'])->whereUuid('id')->middleware('throttle:consultas');
        Route::post('remessas/{id}/despachar', [RemessaController::class, 'despachar'])->whereUuid('id')->middleware('throttle:remessas');
        Route::post('remessas/{id}/cancelar', [RemessaController::class, 'cancelar'])->whereUuid('id')->middleware('throttle:remessas');
        Route::get('remessas/{id}/etiqueta', [RemessaController::class, 'etiqueta'])->whereUuid('id')->middleware('throttle:consultas');

        // Devolucoes (uma remessa no sentido contrario, de um envio ja entregue)
        Route::post('devolucoes', [DevolucaoController::class, 'store'])->middleware('throttle:remessas');
        Route::get('devolucoes/{id}', [DevolucaoController::class, 'show'])->whereUuid('id')->middleware('throttle:consultas');
        Route::get('devolucoes/{id}/etiqueta', [DevolucaoController::class, 'etiqueta'])->whereUuid('id')->middleware('throttle:consultas');
    });
});
