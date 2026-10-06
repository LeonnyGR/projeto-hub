<?php

declare(strict_types=1);

use App\Models\Cotacao;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// O seller vem da URL (/sellers/{seller}/...) e toda consulta parte dele ($seller->remessas(), $seller->cotacoes()).
// Estes testes garantem que os dados de um seller nunca aparecem no outro.

it('um seller nao enxerga cotacao nem remessa de outro', function () {
    [$a] = $this->criarSeller('A');
    [$b] = $this->criarSeller('B');

    $cotacaoB = $this->criarCotacao($b);
    $remessaB = $this->criarRemessa($b, $cotacaoB);
    $remessaB->eventos()->create(['status' => 'postada', 'ocorrido_em' => now()]);

    expect($a->cotacoes()->find($cotacaoB->id))->toBeNull()
        ->and($a->remessas()->find($remessaB->id))->toBeNull()
        ->and($a->remessas()->count())->toBe(0)
        ->and($b->remessas()->find($remessaB->id))->not->toBeNull()
        ->and($b->remessas()->find($remessaB->id)->eventos)->toHaveCount(1);
});

it('um seller nao altera nem apaga dados de outro', function () {
    [$a] = $this->criarSeller('A');
    [$b] = $this->criarSeller('B');
    $cotacaoB = $this->criarCotacao($b);

    expect($a->cotacoes()->whereKey($cotacaoB->id)->update(['cep_destino' => '99999999']))->toBe(0)
        ->and($a->cotacoes()->whereKey($cotacaoB->id)->delete())->toBe(0)
        ->and(Cotacao::find($cotacaoB->id)->cep_destino)->toBe('20040020');
});

it('seller_id nao pode ser enviado em massa (nao esta em $fillable)', function () {
    [$a] = $this->criarSeller('A');

    // Sem passar pela relacao, o seller_id fica vazio e o banco recusa.
    expect(fn () => Cotacao::create([
        'seller_id' => $a->id, 'cep_origem' => '01310100', 'cep_destino' => '20040020',
        'requisicao' => [], 'resultados' => [], 'expira_em' => now()->addMinutes(15),
    ]))->toThrow(QueryException::class);
});

it('na API, recurso de outro seller responde 404 (e nao 403)', function () {
    Route::middleware(['api', 'auth.cliente', 'seller.da.url'])->get('/api/v1/sellers/{seller}/_teste/cotacoes/{id}', function (Request $request, string $id) {
        return response()->json(['id' => $request->attributes->get('seller')->cotacoes()->findOrFail($id)->id]);
    });

    [$a, $chave] = $this->criarSeller('A');
    [$b] = $this->criarSeller('B');
    $cotacaoB = $this->criarCotacao($b);

    // B enxerga a propria cotacao...
    $this->getJson("/api/v1/sellers/{$b->id}/_teste/cotacoes/{$cotacaoB->id}", $this->auth($chave))->assertOk();

    // ...mas pedir a cotacao do B na URL do A da 404
    $this->getJson("/api/v1/sellers/{$a->id}/_teste/cotacoes/{$cotacaoB->id}", $this->auth($chave))
        ->assertStatus(404)->assertJsonPath('erro.codigo', 'nao_encontrado');
});

it('seller que nao existe na URL da 404, inclusive id que nao e uuid', function () {
    [, $chave] = $this->criarSeller();

    $this->getJson('/api/v1/sellers/00000000-0000-0000-0000-000000000000/_teste/eu', $this->auth($chave))->assertStatus(404);
    $this->getJson('/api/v1/sellers/nao-e-uuid/_teste/eu', $this->auth($chave))->assertStatus(404);
});

it('o seller da URL e o que a rota enxerga (nada se mistura entre chamadas)', function () {
    [$a, $chave] = $this->criarSeller('A');
    [$b] = $this->criarSeller('B');

    $this->getJson($this->urlDoSeller($a, '/_teste/eu'), $this->auth($chave))->assertJson(['seller_id' => $a->id]);
    $this->getJson($this->urlDoSeller($b, '/_teste/eu'), $this->auth($chave))->assertJson(['seller_id' => $b->id]);
    $this->getJson($this->urlDoSeller($a, '/_teste/eu'), $this->auth($chave))->assertJson(['seller_id' => $a->id]);
});

it('o banco impede remessa ligada a cotacao de outro seller (FK composta)', function () {
    [$a] = $this->criarSeller('A');
    [$b] = $this->criarSeller('B');
    $cotacaoB = $this->criarCotacao($b);

    expect(fn () => $this->criarRemessa($a, $cotacaoB))->toThrow(QueryException::class);
});
