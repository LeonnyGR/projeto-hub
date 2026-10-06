<?php

declare(strict_types=1);

use App\Enums\StatusRemessa;
use App\Models\Remessa;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('etiquetas');
    config(['frete.etiquetas_disco' => 'etiquetas']);
});

/** Remessa ja entregue, pronta para ter devolucao. Devolve [seller, chaveApi, id da remessa]. */
function remessaEntregue($teste, string $transportadora = 'jadlog'): array
{
    [$seller, $chave] = $teste->criarSellerComAsDuas();
    $cotacao = $teste->criarCotacaoComOpcoes($seller);
    $id = $teste->postJson("/api/v1/sellers/{$seller->id}/remessas", $teste->dadosDeRemessa($cotacao, $transportadora), $teste->comChave($chave))->assertCreated()->json('data.id');
    $seller->remessas()->whereKey($id)->update(['status' => StatusRemessa::Entregue->value]);

    return [$seller, $chave, $id];
}

it('cria devolucao de remessa entregue, com origem e destino invertidos', function () {
    [$seller, $chave, $remessaId] = remessaEntregue($this);

    $r = $this->postJson("/api/v1/sellers/{$seller->id}/devolucoes", ['remessa_id' => $remessaId, 'motivo' => 'Produto com defeito'], $this->comChave($chave))
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'false')
        ->assertJsonPath('data.tipo', 'devolucao')
        ->assertJsonPath('data.status', 'criada')
        ->assertJsonPath('data.remessa_origem_id', $remessaId)
        ->assertJsonPath('data.motivo', 'Produto com defeito')
        ->assertJsonPath('data.valor_frete_centavos', null)
        // quem recebeu o pedido agora despacha...
        ->assertJsonPath('data.remetente.nome', 'Maria da Silva')
        // ...e o seller recebe
        ->assertJsonPath('data.destinatario.nome', 'Loja do Seller Ltda')
        ->assertJsonMissingPath('data.cotacao_id');

    expect($r->json('data.codigo_rastreio'))->not->toBeNull()
        ->and($r->json('data.codigo_rastreio'))->not->toBe($seller->remessas()->find($remessaId)->codigo_rastreio);

    $this->getJson("/api/v1/sellers/{$seller->id}/devolucoes/".$r->json('data.id'), $this->auth($chave))->assertOk()->assertJsonPath('data.tipo', 'devolucao');
    // devolucao nao aparece como remessa (e vice-versa)
    $this->getJson("/api/v1/sellers/{$seller->id}/remessas/".$r->json('data.id'), $this->auth($chave))->assertStatus(404);
    $this->getJson("/api/v1/sellers/{$seller->id}/devolucoes/{$remessaId}", $this->auth($chave))->assertStatus(404);
});

it('na Jadlog a devolucao usa Dropoff, o B2C paga o frete e os enderecos sao os invertidos', function () {
    [$seller, $chave, $remessaId] = remessaEntregue($this);
    Http::swap(new Factory());
    Http::fake(['*' => Http::response(['codigo' => '123456789', 'shipmentId' => '22222222222222', 'status' => 'ok'], 200)]);

    $this->postJson("/api/v1/sellers/{$seller->id}/devolucoes", ['remessa_id' => $remessaId, 'motivo' => 'Tamanho errado'], $this->comChave($chave))->assertCreated();

    Http::assertSent(function ($request) {
        $b = $request->data();

        return str_ends_with($request->url(), '/pedido/incluir')
            && $b['servico'] === 2 && $b['tpColeta'] === 'S' && $b['conteudo'] === 'DEVOLUCAO'
            && $b['exp']['nome'] === 'Maria da Silva' && $b['rem']['nome'] === 'Maria da Silva'
            && $b['des']['nome'] === 'Loja do Seller Ltda' && $b['des']['cep'] === '01310100'
            && $b['tomador']['cnpjCpf'] === '12345678000195'; // o B2C (titular do contrato) paga
    });
});

it('devolucao nos Correios cria uma pre-postagem com os papeis trocados', function () {
    [$seller, $chave, $remessaId] = remessaEntregue($this, 'correios');

    $this->postJson("/api/v1/sellers/{$seller->id}/devolucoes", ['remessa_id' => $remessaId, 'motivo' => 'Arrependimento'], $this->comChave($chave))
        ->assertCreated()->assertJsonPath('data.transportadora', 'correios');
});

it('so permite devolucao de remessa entregue', function (string $status) {
    [$seller, $chave, $remessaId] = remessaEntregue($this);
    $seller->remessas()->whereKey($remessaId)->update(['status' => $status]);

    $this->postJson("/api/v1/sellers/{$seller->id}/devolucoes", ['remessa_id' => $remessaId, 'motivo' => 'Qualquer'], $this->comChave($chave))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'remessa_nao_entregue');
})->with(['criada', 'despachada', 'em_transito', 'cancelada']);

it('nao permite duas devolucoes ativas para a mesma remessa, mas permite outra se a primeira foi cancelada', function () {
    [$seller, $chave, $remessaId] = remessaEntregue($this);
    $primeira = $this->postJson("/api/v1/sellers/{$seller->id}/devolucoes", ['remessa_id' => $remessaId, 'motivo' => 'Defeito'], $this->comChave($chave))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/sellers/{$seller->id}/devolucoes", ['remessa_id' => $remessaId, 'motivo' => 'Defeito'], $this->comChave($chave))
        ->assertStatus(409)->assertJsonPath('erro.codigo', 'devolucao_ja_existe');

    $seller->remessas()->whereKey($primeira)->update(['status' => 'cancelada']);
    $this->postJson("/api/v1/sellers/{$seller->id}/devolucoes", ['remessa_id' => $remessaId, 'motivo' => 'Defeito'], $this->comChave($chave))->assertCreated();
});

it('devolucao tambem e idempotente', function () {
    [$seller, $chave, $remessaId] = remessaEntregue($this);
    $dados = ['remessa_id' => $remessaId, 'motivo' => 'Defeito'];
    $headers = $this->comChave($chave, 'devolucao-chave-1');

    $primeira = $this->postJson("/api/v1/sellers/{$seller->id}/devolucoes", $dados, $headers)->assertCreated();
    $segunda = $this->postJson("/api/v1/sellers/{$seller->id}/devolucoes", $dados, $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($segunda->json('data.id'))->toBe($primeira->json('data.id'))
        ->and($seller->remessas()->where('tipo', 'devolucao')->count())->toBe(1);

    $this->postJson("/api/v1/sellers/{$seller->id}/devolucoes", ['remessa_id' => $remessaId, 'motivo' => 'Outro motivo'], $headers)
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'idempotency_key_reutilizada');
});

it('valida a entrada da devolucao', function () {
    [$seller, $chave, $remessaId] = remessaEntregue($this);

    $this->postJson("/api/v1/sellers/{$seller->id}/devolucoes", ['remessa_id' => $remessaId], $this->comChave($chave))->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['motivo']]]);
    $this->postJson("/api/v1/sellers/{$seller->id}/devolucoes", ['remessa_id' => 'x', 'motivo' => 'Defeito'], $this->comChave($chave))->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['remessa_id']]]);
    $this->postJson("/api/v1/sellers/{$seller->id}/devolucoes", ['remessa_id' => $remessaId, 'motivo' => 'Defeito', 'valor' => 1], $this->comChave($chave))->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['valor']]]);
    $this->postJson("/api/v1/sellers/{$seller->id}/devolucoes", ['remessa_id' => $remessaId, 'motivo' => 'Defeito'], $this->auth($chave))->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['idempotency_key']]]);
});

it('remessa inexistente ou de outro seller da 404', function () {
    [$a, $chave, $remessaId] = remessaEntregue($this);
    [$b] = $this->criarSellerComAsDuas('B');

    // a remessa e do A; pedir devolucao dela pela URL do B nao encontra
    $this->postJson($this->urlDoSeller($b, '/devolucoes'), ['remessa_id' => $remessaId, 'motivo' => 'Defeito'], $this->comChave($chave))
        ->assertStatus(404)->assertJsonPath('erro.codigo', 'remessa_nao_encontrada');
    $this->postJson($this->urlDoSeller($a, '/devolucoes'), ['remessa_id' => '00000000-0000-0000-0000-000000000000', 'motivo' => 'Defeito'], $this->comChave($chave))
        ->assertStatus(404);
});

it('um seller nao ve a devolucao de outro', function () {
    [$a, $chave, $remessaId] = remessaEntregue($this);
    [$b] = $this->criarSellerComAsDuas('B');
    $id = $this->postJson($this->urlDoSeller($a, '/devolucoes'), ['remessa_id' => $remessaId, 'motivo' => 'Defeito'], $this->comChave($chave))->json('data.id');

    $this->getJson($this->urlDoSeller($b, "/devolucoes/{$id}"), $this->auth($chave))->assertStatus(404);
    $this->getJson($this->urlDoSeller($b, "/devolucoes/{$id}/etiqueta"), $this->auth($chave))->assertStatus(404);
});

it('devolve a etiqueta da devolucao', function () {
    [$seller, $chave, $remessaId] = remessaEntregue($this);
    $id = $this->postJson("/api/v1/sellers/{$seller->id}/devolucoes", ['remessa_id' => $remessaId, 'motivo' => 'Defeito'], $this->comChave($chave))->json('data.id');

    $this->get("/api/v1/sellers/{$seller->id}/devolucoes/{$id}/etiqueta", $this->auth($chave))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('falha da transportadora na devolucao nao grava nada e libera a chave', function () {
    [$seller, $chave, $remessaId] = remessaEntregue($this);
    $seller->credenciais()->where('transportadora', 'jadlog')->first()->update(['credenciais' => ['token' => 'invalido']]);

    $this->postJson("/api/v1/sellers/{$seller->id}/devolucoes", ['remessa_id' => $remessaId, 'motivo' => 'Defeito'], $this->comChave($chave))
        ->assertStatus(502)->assertJsonPath('erro.detalhes.motivo', 'credencial_invalida');

    expect($seller->remessas()->where('tipo', 'devolucao')->count())->toBe(0);
});
