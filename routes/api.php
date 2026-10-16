<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ClienteController;
use App\Http\Controllers\ContaController;
use App\Http\Controllers\CotacaoController;
use App\Http\Controllers\DevolucaoController;
use App\Http\Controllers\EnderecoController;
use App\Http\Controllers\Portal\ChaveApiController;
use App\Http\Controllers\Portal\CredencialController;
use App\Http\Controllers\Portal\PermissaoController;
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
    Route::get('transportadoras', [TransportadoraController::class, 'index'])->middleware(['throttle:cadastros', 'permite:TRA_01,ver']);
    Route::get('transportadoras/catalogo', [TransportadoraController::class, 'catalogo'])->middleware(['throttle:cadastros', 'permite:TRA_01,ver']);
    Route::get('transportadoras/{transportadora}', [TransportadoraController::class, 'show'])
        ->whereIn('transportadora', Transportadoras::codigos())->middleware(['throttle:cadastros', 'permite:TRA_01,ver']);

    // Consultas
    Route::get('enderecos/{cep}', [EnderecoController::class, 'show'])->middleware('throttle:consultas');
    Route::get('pontos-retirada', [PontoRetiradaController::class, 'index'])->middleware('throttle:consultas');

    // Cotacao
    Route::get('cotacoes', [CotacaoController::class, 'index'])->middleware(['throttle:consultas', 'permite:COT_02,ver']);
    Route::post('cotacoes', [CotacaoController::class, 'store'])->middleware(['throttle:cotacoes', 'permite:COT_01,criar']);
    Route::get('cotacoes/{id}', [CotacaoController::class, 'show'])->whereUuid('id')->middleware(['throttle:consultas', 'permite:COT_02,ver']);

    // Remessas (envios). Criar exige o header Idempotency-Key.
    Route::get('remessas', [RemessaController::class, 'index'])->middleware(['throttle:consultas', 'permite:OPE_02,ver']);
    Route::post('remessas', [RemessaController::class, 'store'])->middleware(['throttle:remessas', 'permite:OPE_01,criar']);
    Route::get('remessas/{id}', [RemessaController::class, 'show'])->whereUuid('id')->middleware(['throttle:consultas', 'permite:OPE_02,ver']);
    Route::post('remessas/{id}/despachar', [RemessaController::class, 'despachar'])->whereUuid('id')->middleware(['throttle:remessas', 'permite:OPE_02,despachar']);
    Route::post('remessas/{id}/cancelar', [RemessaController::class, 'cancelar'])->whereUuid('id')->middleware(['throttle:remessas', 'permite:OPE_02,cancelar']);
    Route::get('remessas/{id}/etiqueta', [RemessaController::class, 'etiqueta'])->whereUuid('id')->middleware(['throttle:consultas', 'permite:OPE_02,etiqueta']);

    // Devolucoes (uma remessa no sentido contrario, de um envio ja entregue)
    Route::get('devolucoes', [DevolucaoController::class, 'index'])->middleware(['throttle:consultas', 'permite:REV_01,ver']);
    Route::post('devolucoes', [DevolucaoController::class, 'store'])->middleware(['throttle:remessas', 'permite:REV_02,criar']);
    Route::get('devolucoes/{id}', [DevolucaoController::class, 'show'])->whereUuid('id')->middleware(['throttle:consultas', 'permite:REV_01,ver']);
    Route::get('devolucoes/{id}/etiqueta', [DevolucaoController::class, 'etiqueta'])->whereUuid('id')->middleware(['throttle:consultas', 'permite:REV_01,etiqueta']);
};

// Configuracao: cadastrar o contrato com as transportadoras (as respostas nunca trazem segredo).
// No portal, quem tem TRA_01 "editar" (o dono sempre). (O comando transportadora:configurar faz o mesmo pelo terminal.)
$rotasDeConfiguracao = function () {
    Route::put('transportadoras/{transportadora}', [TransportadoraController::class, 'update'])
        ->whereIn('transportadora', Transportadoras::codigos())->middleware(['throttle:cadastros', 'permite:TRA_01,editar']);
    Route::patch('transportadoras/{transportadora}', [TransportadoraController::class, 'ativar'])
        ->whereIn('transportadora', Transportadoras::codigos())->middleware(['throttle:cadastros', 'permite:TRA_01,editar']);
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

        // Cadastro do contrato com as transportadoras: quem tem TRA_01 "editar".
        $rotasDeConfiguracao();

        // Ver as credenciais por inteiro (pede a senha do dono de novo): so o dono, nunca por permissao.
        Route::post('transportadoras/{transportadora}/credenciais', [CredencialController::class, 'revelar'])
            ->whereIn('transportadora', Transportadoras::codigos())->middleware(['papel:adm_tenant', 'throttle:revelar']);

        // Administracao (ADM_01 e ADM_02): o dono pode tudo; operador so se o dono liberar.
        Route::get('chaves', [ChaveApiController::class, 'index'])->middleware(['throttle:cadastros', 'permite:ADM_01,ver']);
        Route::post('chaves', [ChaveApiController::class, 'store'])->middleware(['throttle:cadastros', 'permite:ADM_01,criar']);
        Route::delete('chaves/{id}', [ChaveApiController::class, 'destroy'])->whereUuid('id')->middleware(['throttle:cadastros', 'permite:ADM_01,excluir']);

        Route::get('usuarios', [UsuarioController::class, 'index'])->middleware(['throttle:cadastros', 'permite:ADM_02,ver']);
        Route::post('usuarios', [UsuarioController::class, 'store'])->middleware(['throttle:cadastros', 'permite:ADM_02,criar']);
        Route::patch('usuarios/{id}', [UsuarioController::class, 'update'])->whereUuid('id')->middleware(['throttle:cadastros', 'permite:ADM_02,editar']);

        // Permissoes: o catalogo (para montar a tela) e o que cada operador pode fazer.
        Route::get('permissoes/catalogo', [PermissaoController::class, 'catalogo'])->middleware(['throttle:cadastros', 'permite:ADM_02,ver']);
        Route::get('usuarios/{id}/permissoes', [PermissaoController::class, 'show'])->whereUuid('id')->middleware(['throttle:cadastros', 'permite:ADM_02,ver']);
        Route::put('usuarios/{id}/permissoes', [PermissaoController::class, 'update'])->whereUuid('id')->middleware(['throttle:cadastros', 'permite:ADM_02,editar']);
    });
});
