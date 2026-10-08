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

/** Remessa ja entregue, pronta para ter devolucao. Devolve [cliente, chaveApi, id da remessa]. */
function remessaEntregue($teste, string $transportadora = 'jadlog'): array
{
    [$cliente, $chave] = $teste->criarClienteComAsDuas();
    $cotacao = $teste->criarCotacaoComOpcoes($cliente);
    $id = $teste->postJson('/api/v1/remessas', $teste->dadosDeRemessa($cotacao, $transportadora), $teste->comChave($chave))->assertCreated()->json('data.id');
    $cliente->remessas()->whereKey($id)->update(['status' => StatusRemessa::Entregue->value]);

    return [$cliente, $chave, $id];
}

it('cria devolucao de remessa entregue, com origem e destino invertidos', function () {
    [$cliente, $chave, $remessaId] = remessaEntregue($this);

    $r = $this->postJson('/api/v1/devolucoes', ['remessa_id' => $remessaId, 'motivo' => 'Produto com defeito'], $this->comChave($chave))
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'false')
        ->assertJsonPath('data.tipo', 'devolucao')
        ->assertJsonPath('data.status', 'criada')
        ->assertJsonPath('data.remessa_origem_id', $remessaId)
        ->assertJsonPath('data.motivo', 'Produto com defeito')
        ->assertJsonPath('data.valor_frete_centavos', null)
        // quem recebeu o pedido agora despacha...
        ->assertJsonPath('data.remetente.nome', 'Maria da Silva')
        // ...e o cliente recebe
        ->assertJsonPath('data.destinatario.nome', 'Loja do Joao Ltda')
        ->assertJsonMissingPath('data.cotacao_id');

    expect($r->json('data.codigo_rastreio'))->not->toBeNull()
        ->and($r->json('data.codigo_rastreio'))->not->toBe($cliente->remessas()->find($remessaId)->codigo_rastreio);

    $this->getJson("/api/v1/devolucoes/".$r->json('data.id'), $this->auth($chave))->assertOk()->assertJsonPath('data.tipo', 'devolucao');
    // devolucao nao aparece como remessa (e vice-versa)
    $this->getJson("/api/v1/remessas/".$r->json('data.id'), $this->auth($chave))->assertStatus(404);
    $this->getJson("/api/v1/devolucoes/{$remessaId}", $this->auth($chave))->assertStatus(404);
});

it('na Jadlog a devolucao usa Dropoff, o cliente (titular do contrato) paga o frete e os enderecos sao os invertidos', function () {
    [$cliente, $chave, $remessaId] = remessaEntregue($this);
    Http::swap(new Factory());
    Http::fake(['*' => Http::response(['codigo' => '123456789', 'shipmentId' => '22222222222222', 'status' => 'ok'], 200)]);

    $this->postJson('/api/v1/devolucoes', ['remessa_id' => $remessaId, 'motivo' => 'Tamanho errado'], $this->comChave($chave))->assertCreated();

    Http::assertSent(function ($request) {
        $b = $request->data();

        return str_ends_with($request->url(), '/pedido/incluir')
            && $b['servico'] === 2 && $b['tpColeta'] === 'S' && $b['conteudo'] === 'DEVOLUCAO'
            && $b['exp']['nome'] === 'Maria da Silva' && $b['rem']['nome'] === 'Maria da Silva'
            && $b['des']['nome'] === 'Loja do Joao Ltda' && $b['des']['cep'] === '01310100'
            && $b['tomador']['cnpjCpf'] === '12345678000195'; // o titular do contrato paga
    });
});

it('devolucao nos Correios cria uma pre-postagem com os papeis trocados', function () {
    [$cliente, $chave, $remessaId] = remessaEntregue($this, 'correios');

    $this->postJson('/api/v1/devolucoes', ['remessa_id' => $remessaId, 'motivo' => 'Arrependimento'], $this->comChave($chave))
        ->assertCreated()->assertJsonPath('data.transportadora', 'correios');
});

it('so permite devolucao de remessa entregue', function (string $status) {
    [$cliente, $chave, $remessaId] = remessaEntregue($this);
    $cliente->remessas()->whereKey($remessaId)->update(['status' => $status]);

    $this->postJson('/api/v1/devolucoes', ['remessa_id' => $remessaId, 'motivo' => 'Qualquer'], $this->comChave($chave))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'remessa_nao_entregue');
})->with(['criada', 'despachada', 'em_transito', 'cancelada']);

it('nao permite duas devolucoes ativas para a mesma remessa, mas permite outra se a primeira foi cancelada', function () {
    [$cliente, $chave, $remessaId] = remessaEntregue($this);
    $primeira = $this->postJson('/api/v1/devolucoes', ['remessa_id' => $remessaId, 'motivo' => 'Defeito'], $this->comChave($chave))->assertCreated()->json('data.id');

    $this->postJson('/api/v1/devolucoes', ['remessa_id' => $remessaId, 'motivo' => 'Defeito'], $this->comChave($chave))
        ->assertStatus(409)->assertJsonPath('erro.codigo', 'devolucao_ja_existe');

    $cliente->remessas()->whereKey($primeira)->update(['status' => 'cancelada']);
    $this->postJson('/api/v1/devolucoes', ['remessa_id' => $remessaId, 'motivo' => 'Defeito'], $this->comChave($chave))->assertCreated();
});

it('devolucao tambem e idempotente', function () {
    [$cliente, $chave, $remessaId] = remessaEntregue($this);
    $dados = ['remessa_id' => $remessaId, 'motivo' => 'Defeito'];
    $headers = $this->comChave($chave, 'devolucao-chave-1');

    $primeira = $this->postJson('/api/v1/devolucoes', $dados, $headers)->assertCreated();
    $segunda = $this->postJson('/api/v1/devolucoes', $dados, $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($segunda->json('data.id'))->toBe($primeira->json('data.id'))
        ->and($cliente->remessas()->where('tipo', 'devolucao')->count())->toBe(1);

    $this->postJson('/api/v1/devolucoes', ['remessa_id' => $remessaId, 'motivo' => 'Outro motivo'], $headers)
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'idempotency_key_reutilizada');
});

it('valida a entrada da devolucao', function () {
    [$cliente, $chave, $remessaId] = remessaEntregue($this);

    $this->postJson('/api/v1/devolucoes', ['remessa_id' => $remessaId], $this->comChave($chave))->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['motivo']]]);
    $this->postJson('/api/v1/devolucoes', ['remessa_id' => 'x', 'motivo' => 'Defeito'], $this->comChave($chave))->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['remessa_id']]]);
    $this->postJson('/api/v1/devolucoes', ['remessa_id' => $remessaId, 'motivo' => 'Defeito', 'valor' => 1], $this->comChave($chave))->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['valor']]]);
    $this->postJson('/api/v1/devolucoes', ['remessa_id' => $remessaId, 'motivo' => 'Defeito'], $this->auth($chave))->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['idempotency_key']]]);
});

it('remessa inexistente ou de outro cliente da 404', function () {
    [, $chave, $remessaId] = remessaEntregue($this);
    [, $chaveB] = $this->criarClienteComAsDuas('B');

    // a remessa e do A; o B pedir devolucao dela nao encontra
    $this->postJson('/api/v1/devolucoes', ['remessa_id' => $remessaId, 'motivo' => 'Defeito'], $this->comChave($chaveB))
        ->assertStatus(404)->assertJsonPath('erro.codigo', 'remessa_nao_encontrada');
    $this->postJson('/api/v1/devolucoes', ['remessa_id' => '00000000-0000-0000-0000-000000000000', 'motivo' => 'Defeito'], $this->comChave($chave))
        ->assertStatus(404);
});

it('um cliente nao ve a devolucao de outro', function () {
    [, $chave, $remessaId] = remessaEntregue($this);
    [, $chaveB] = $this->criarClienteComAsDuas('B');
    $id = $this->postJson('/api/v1/devolucoes', ['remessa_id' => $remessaId, 'motivo' => 'Defeito'], $this->comChave($chave))->json('data.id');

    $this->getJson("/api/v1/devolucoes/{$id}", $this->auth($chave))->assertOk();
    $this->getJson("/api/v1/devolucoes/{$id}", $this->auth($chaveB))->assertStatus(404);
    $this->getJson("/api/v1/devolucoes/{$id}/etiqueta", $this->auth($chaveB))->assertStatus(404);
});

it('devolve a etiqueta da devolucao', function () {
    [$cliente, $chave, $remessaId] = remessaEntregue($this);
    $id = $this->postJson('/api/v1/devolucoes', ['remessa_id' => $remessaId, 'motivo' => 'Defeito'], $this->comChave($chave))->json('data.id');

    $this->get("/api/v1/devolucoes/{$id}/etiqueta", $this->auth($chave))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('falha da transportadora na devolucao nao grava nada e libera a chave', function () {
    [$cliente, $chave, $remessaId] = remessaEntregue($this);
    $cliente->credenciais()->where('transportadora', 'jadlog')->first()->update(['credenciais' => ['token' => 'invalido']]);

    $this->postJson('/api/v1/devolucoes', ['remessa_id' => $remessaId, 'motivo' => 'Defeito'], $this->comChave($chave))
        ->assertStatus(502)->assertJsonPath('erro.detalhes.motivo', 'credencial_invalida');

    expect($cliente->remessas()->where('tipo', 'devolucao')->count())->toBe(0);
});
