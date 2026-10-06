<?php

declare(strict_types=1);

use App\Enums\StatusRemessa;
use App\Models\ChamadaTransportadora;
use App\Models\Cotacao;
use App\Models\Remessa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('etiquetas');
    config(['frete.etiquetas_disco' => 'etiquetas']);
});

/** Seller com as duas transportadoras e uma cotacao pronta. Devolve [seller, chaveApi, cotacao]. */
function cenarioDeRemessa($teste, string $cepDestino = '20040020'): array
{
    [$seller, $chave] = $teste->criarSellerComAsDuas();

    return [$seller, $chave, $teste->criarCotacaoComOpcoes($seller, $cepDestino)];
}

// ---------------------------------------------------------------- criar

it('cria remessa na Jadlog: codigo, volumes, etiqueta guardada e dados pessoais criptografados', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);

    $r = $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao), $this->comChave($chave))
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'false')
        ->assertJsonPath('data.tipo', 'envio')
        ->assertJsonPath('data.status', 'criada')
        ->assertJsonPath('data.transportadora', 'jadlog')
        ->assertJsonPath('data.valor_frete_centavos', 1460)
        ->assertJsonPath('data.prazo_dias', 6)
        ->assertJsonPath('data.etiqueta_disponivel', true)
        ->assertJsonPath('data.destinatario.nome', 'Maria da Silva');

    $codigo = $r->json('data.codigo_rastreio');
    expect($codigo)->toMatch('/^\d{14}$/')
        ->and($r->json('data.volumes'))->toHaveCount(1)
        ->and($r->json('data.volumes.0.codigo_rastreio'))->toBe($codigo)
        ->and($r->json('data.volumes.0.peso_gramas'))->toBe(1200);

    // Etiqueta no Storage, nunca no banco
    $remessa = $seller->remessas()->findOrFail($r->json('data.id'));
    Storage::disk('etiquetas')->assertExists($remessa->etiqueta_caminho);
    expect(Storage::disk('etiquetas')->get($remessa->etiqueta_caminho))->toStartWith('%PDF');

    // Dados pessoais criptografados em repouso
    $bruto = DB::table('remessas')->where('id', $remessa->id)->value('destinatario');
    expect($bruto)->not->toContain('Maria da Silva')->not->toContain('52998224725');
});

it('envia para a Jadlog o pedido no formato do PDF', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
    \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['codigo' => '123456789', 'shipmentId' => '11111111111111', 'status' => 'ok'], 200)]);

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao, 'jadlog', ['conteudo' => 'CAMISETA']), $this->comChave($chave))->assertCreated();

    \Illuminate\Support\Facades\Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/pedido/incluir')) {
            return false;
        }
        $b = $request->data();

        return $request->header('Authorization')[0] === 'token-jadlog'
            && $b['codCliente'] === '000123'
            && $b['conteudo'] === 'CAMISETA'
            && $b['pedido'] === ['PED-1001']
            && $b['totPeso'] === 1.2 && $b['totValor'] === 150.0
            && $b['modalidade'] === 3 && $b['tpColeta'] === 'K' && $b['servico'] === 0 && $b['tipoFrete'] === 0
            // quem paga (tomador) e o titular do contrato (B2C); quem despacha (exp, rem) e o seller; quem recebe (des) e a Maria
            && $b['tomador']['cnpjCpf'] === '12345678000195' && $b['tomador']['nome'] === 'Bartofil Comercio Ltda'
            && $b['exp']['cnpjCpf'] === '11222333000181' && $b['rem']['nome'] === 'Loja do Seller Ltda'
            && $b['des']['nome'] === 'Maria da Silva' && $b['des']['cep'] === '20040020' && $b['des']['cel'] === '21 987654321' && $b['des']['fone'] === null
            && $b['rem']['fone'] === '11 33334444' && $b['rem']['cel'] === null
            // sem nota fiscal: declaracao de conteudo manual
            && $b['dfe'][0]['tpDocumento'] === 0 && str_starts_with($b['dfe'][0]['nrDoc'], 'DEC') && $b['dfe'][0]['valor'] === 150.0
            && $b['volume'][0] === ['altura' => 10, 'comprimento' => 30, 'largura' => 20, 'peso' => 1.2, 'identificador' => $b['volume'][0]['identificador']];
    });
});

it('cria remessa nos Correios com um codigo de rastreio por volume', function () {
    [$seller, $chave] = $this->criarSellerComAsDuas();
    $cotacao = $seller->cotacoes()->create([
        'cep_origem' => '01310100', 'cep_destino' => '20040020',
        'requisicao' => $this->dadosDeCotacao(['volumes' => [
            ['peso_gramas' => 500, 'altura_cm' => 10, 'largura_cm' => 10, 'comprimento_cm' => 10],
            ['peso_gramas' => 800, 'altura_cm' => 20, 'largura_cm' => 15, 'comprimento_cm' => 12],
        ]]),
        'resultados' => ['opcoes' => [['transportadora' => 'correios', 'servico_codigo' => '03220', 'servico_nome' => 'SEDEX', 'valor_centavos' => 4000, 'prazo_dias' => 2]], 'falhas' => []],
        'expira_em' => now()->addMinutes(15),
    ]);

    $r = $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao, 'correios'), $this->comChave($chave))
        ->assertCreated()
        ->assertJsonPath('data.transportadora', 'correios')
        // Correios: a etiqueta so e gerada quando pedida
        ->assertJsonPath('data.etiqueta_disponivel', false);

    $codigos = array_column($r->json('data.volumes'), 'codigo_rastreio');
    expect($codigos)->toHaveCount(2)
        ->and($codigos[0])->not->toBe($codigos[1])
        ->and($codigos[0])->toMatch('/^[A-Z]{2}\d{9}BR$/')
        ->and($r->json('data.codigo_rastreio'))->toBe($codigos[0])
        ->and(ChamadaTransportadora::where('operacao', 'criar_remessa')->count())->toBe(2);

    // Dois volumes = duas pre-postagens, com ids da transportadora diferentes
    $remessa = $seller->remessas()->findOrFail($r->json('data.id'));
    expect(array_unique(array_column($remessa->volumes, 'id_externo')))->toHaveCount(2);
});

it('envia aos Correios a pre-postagem com remetente, destinatario e medidas', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
    \Illuminate\Support\Facades\Http::fake([
        '*/token/*' => \Illuminate\Support\Facades\Http::response(['token' => 'tk', 'expiraEm' => now('America/Sao_Paulo')->addDay()->format('Y-m-d\TH:i:s')], 201),
        '*' => \Illuminate\Support\Facades\Http::response(['id' => 'PRABC', 'codigoObjeto' => 'AA123456789BR'], 200),
    ]);

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao, 'correios'), $this->comChave($chave))->assertCreated();

    \Illuminate\Support\Facades\Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/prepostagem/v1/prepostagens')) {
            return false;
        }
        $b = $request->data();

        return $request->header('Authorization')[0] === 'Bearer tk'
            && $b['codigoServico'] === '03220' && $b['codigoFormatoObjetoInformado'] === '2'
            && $b['pesoInformado'] === '1200' && $b['alturaInformada'] === '10' && $b['larguraInformada'] === '20' && $b['comprimentoInformado'] === '30'
            && $b['remetente']['cpfCnpj'] === '11222333000181' && $b['remetente']['dddTelefone'] === '11' && $b['remetente']['telefone'] === '33334444' && $b['remetente']['celular'] === ''
            && $b['destinatario']['dddCelular'] === '21' && $b['destinatario']['celular'] === '987654321' && $b['destinatario']['telefone'] === ''
            && $b['destinatario']['endereco'] === ['cep' => '20040020', 'logradouro' => 'Rua da Assembleia', 'numero' => '10', 'complemento' => '', 'bairro' => 'Centro', 'cidade' => 'Rio de Janeiro', 'uf' => 'RJ'];
    });
});

it('aceita documento, telefone e CEP com mascara', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $dados = $this->dadosDeRemessa($cotacao, 'jadlog', [
        'remetente' => ['documento' => '11.222.333/0001-81', 'telefone' => '(11) 3333-4444', 'endereco' => ['cep' => '01310-100']],
        'destinatario' => ['documento' => '529.982.247-25', 'endereco' => ['cep' => '20040-020', 'uf' => 'rj']],
    ]);

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $dados, $this->comChave($chave))->assertCreated();
});

// ---------------------------------------------------------------- idempotencia

it('repetir a chamada com a mesma chave devolve a mesma remessa, sem criar outra na transportadora', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $dados = $this->dadosDeRemessa($cotacao);
    $headers = $this->comChave($chave, 'minha-chave-001');

    $primeira = $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $dados, $headers)->assertCreated();
    $segunda = $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $dados, $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($segunda->json('data.id'))->toBe($primeira->json('data.id'))
        ->and($segunda->json('data.codigo_rastreio'))->toBe($primeira->json('data.codigo_rastreio'))
        ->and($seller->remessas()->count())->toBe(1)
        ->and(ChamadaTransportadora::where('operacao', 'criar_remessa')->count())->toBe(1);
});

it('a repeticao funciona mesmo depois que a cotacao expirou', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $dados = $this->dadosDeRemessa($cotacao);
    $headers = $this->comChave($chave, 'minha-chave-002');

    $id = $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $dados, $headers)->assertCreated()->json('data.id');
    $this->travel(30)->minutes();

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $dados, $headers)->assertOk()->assertJsonPath('data.id', $id);
});

it('a ordem dos campos nao muda o pedido (mesma chave continua valendo)', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $dados = $this->dadosDeRemessa($cotacao);
    $headers = $this->comChave($chave, 'minha-chave-003');

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $dados, $headers)->assertCreated();
    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", array_reverse($dados, true), $headers)->assertOk();
});

it('mesma chave com pedido diferente da 422', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $headers = $this->comChave($chave, 'minha-chave-004');

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao), $headers)->assertCreated();

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao, 'jadlog', ['numero_pedido' => 'OUTRO']), $headers)
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'idempotency_key_reutilizada');
});

it('a mesma Idempotency-Key pode ser usada por sellers diferentes', function () {
    [$a, $chave, $cotacaoA] = cenarioDeRemessa($this);
    [$b] = $this->criarSellerComAsDuas('B');
    $cotacaoB = $this->criarCotacaoComOpcoes($b);

    $this->postJson($this->urlDoSeller($a, '/remessas'), $this->dadosDeRemessa($cotacaoA), $this->comChave($chave, 'chave-compartilhada'))->assertCreated();
    $this->postJson($this->urlDoSeller($b, '/remessas'), $this->dadosDeRemessa($cotacaoB), $this->comChave($chave, 'chave-compartilhada'))->assertCreated();
});

it('se a remessa ainda esta sendo criada, a repeticao recebe 409', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $dados = $this->dadosDeRemessa($cotacao);
    $headers = $this->comChave($chave, 'minha-chave-005');

    $id = $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $dados, $headers)->assertCreated()->json('data.id');
    $seller->remessas()->whereKey($id)->update(['status' => 'criando']);

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $dados, $headers)->assertStatus(409)->assertJsonPath('erro.codigo', 'em_processamento');
});

it('quando duas requisicoes disputam a mesma chave, a que chega depois nao cria nada (constraint unica)', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);

    // Simula a outra requisicao: logo depois de a nossa carregar a cotacao, uma concorrente grava a mesma chave.
    Cotacao::retrieved(function () use ($seller, $cotacao) {
        static $feito = false;
        if ($feito) {
            return;
        }
        $feito = true;
        DB::table('remessas')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'seller_id' => $seller->id, 'tipo' => 'envio', 'cotacao_id' => $cotacao->id,
            'idempotency_key' => 'minha-chave-006', 'hash_requisicao' => str_repeat('0', 64), 'transportadora' => 'jadlog',
            'servico_codigo' => '3', 'status' => 'criada', 'valor_frete_centavos' => 1, 'remetente' => '', 'destinatario' => '',
            'volumes' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao), $this->comChave($chave, 'minha-chave-006'))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'idempotency_key_reutilizada');

    expect($seller->remessas()->count())->toBe(1)
        ->and(ChamadaTransportadora::where('operacao', 'criar_remessa')->count())->toBe(0);
});

// ---------------------------------------------------------------- validacao e regras

it('Idempotency-Key e obrigatorio e precisa ter formato valido', function (?string $valor) {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $headers = $this->auth($chave) + ($valor === null ? [] : ['Idempotency-Key' => $valor]);

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao), $headers)
        ->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['idempotency_key']]]);
})->with(['ausente' => [null], 'curta' => ['abc'], 'com espaco' => ['chave com espaco'], 'com caracteres estranhos' => ['chave;drop table']]);

it('valida os dados da remessa', function (array $mudar, string $campo) {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao, 'jadlog', $mudar), $this->comChave($chave))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'validacao_falhou')->assertJsonStructure(['erro' => ['detalhes' => [$campo]]]);
})->with([
    'cpf invalido' => [['destinatario' => ['documento' => '11111111111']], 'destinatario.documento'],
    'cnpj invalido' => [['remetente' => ['documento' => '11222333000182']], 'remetente.documento'],
    'telefone curto' => [['destinatario' => ['telefone' => '12345']], 'destinatario.telefone'],
    'uf invalida' => [['destinatario' => ['endereco' => ['uf' => 'XX']]], 'destinatario.endereco.uf'],
    'cep curto' => [['destinatario' => ['endereco' => ['cep' => '123']]], 'destinatario.endereco.cep'],
    'nome muito longo' => [['destinatario' => ['nome' => str_repeat('a', 61)]], 'destinatario.nome'],
    'logradouro muito longo' => [['destinatario' => ['endereco' => ['logradouro' => str_repeat('a', 61)]]], 'destinatario.endereco.logradouro'],
    'email invalido' => [['destinatario' => ['email' => 'nao-e-email']], 'destinatario.email'],
    'transportadora desconhecida' => [['transportadora' => 'loggi'], 'transportadora'],
    'pedido muito longo' => [['numero_pedido' => str_repeat('1', 21)], 'numero_pedido'],
    'cotacao_id nao e uuid' => [['cotacao_id' => '123'], 'cotacao_id'],
    'nfe sem chave' => [['documento_fiscal' => ['tipo' => 'nfe', 'numero' => '123', 'serie' => '1', 'cfop' => '5102']], 'documento_fiscal.chave'],
    'campo desconhecido' => [['valor_frete_centavos' => 1], 'valor_frete_centavos'],
]);

it('o preco vem da cotacao: o seller nao consegue mandar outro valor nem volumes', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao, 'jadlog', ['volumes' => [['peso_gramas' => 1]]]), $this->comChave($chave))
        ->assertStatus(422)->assertJsonStructure(['erro' => ['detalhes' => ['volumes']]]);
});

it('aceita nota fiscal eletronica completa', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $nota = ['tipo' => 'nfe', 'chave' => str_repeat('1', 44), 'numero' => '12345', 'serie' => '1', 'cfop' => '5102'];

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao, 'jadlog', ['documento_fiscal' => $nota]), $this->comChave($chave))->assertCreated();
});

it('recusa cotacao expirada, de outro seller, inexistente ou com opcao que nao existe', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    [$outro] = $this->criarSellerComAsDuas('Outro');
    $cotacaoDoOutro = $this->criarCotacaoComOpcoes($outro);

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacaoDoOutro), $this->comChave($chave))
        ->assertStatus(404)->assertJsonPath('erro.codigo', 'cotacao_nao_encontrada');

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao, 'jadlog', ['servico_codigo' => '99']), $this->comChave($chave))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'opcao_invalida');

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao, 'jadlog', ['destinatario' => ['endereco' => ['cep' => '99999999']]]), $this->comChave($chave))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'cep_diferente_da_cotacao');

    $this->travel(16)->minutes();
    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao), $this->comChave($chave))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'cotacao_expirada');

    expect($seller->remessas()->count())->toBe(0);
});

it('recusa transportadora que o seller nao tem configurada', function () {
    [$seller, $chave] = $this->criarSeller();
    $this->configurarJadlog($seller);
    $cotacao = $this->criarCotacaoComOpcoes($seller);

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao, 'correios'), $this->comChave($chave))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'transportadora_nao_configurada');
});

it('exige autenticacao', function () {
    [$seller] = $this->criarSeller();
    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", [], ['Idempotency-Key' => 'abcdefgh'])->assertStatus(401);
});

// ---------------------------------------------------------------- falhas da transportadora

it('falhas da transportadora na criacao nao deixam remessa gravada e liberam a chave', function (string $cep, string $transportadora, int $status, string $codigo, string $motivo) {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this, $cep);
    $headers = $this->comChave($chave, 'chave-de-falha-01');

    $r = $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao, $transportadora), $headers)
        ->assertStatus($status)->assertJsonPath('erro.codigo', $codigo)->assertJsonPath('erro.detalhes.motivo', $motivo);

    expect($r->getContent())->not->toContain('Destino fora')->not->toContain('cURL')->not->toContain('TOKEN')
        ->and($seller->remessas()->count())->toBe(0);
})->with([
    'jadlog sem cobertura' => ['22222222', 'jadlog', 422, 'remessa_recusada', 'sem_cobertura'],
    'correios recusa' => ['22222222', 'correios', 422, 'remessa_recusada', 'recusada'],
    'jadlog timeout' => ['33333333', 'jadlog', 502, 'transportadora_indisponivel', 'sem_resposta'],
    'correios timeout' => ['33333333', 'correios', 502, 'transportadora_indisponivel', 'sem_resposta'],
    'jadlog 500' => ['44444444', 'jadlog', 502, 'transportadora_indisponivel', 'erro_na_transportadora'],
    'jadlog resposta quebrada' => ['55555555', 'jadlog', 502, 'transportadora_indisponivel', 'resposta_invalida'],
    'jadlog credencial' => ['66666666', 'jadlog', 502, 'transportadora_indisponivel', 'credencial_invalida'],
]);

it('depois de uma falha o seller pode tentar de novo com a mesma chave', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $headers = $this->comChave($chave, 'chave-de-falha-02');

    // 1a tentativa: Jadlog fora do ar (token recusado)
    $seller->credenciais()->where('transportadora', 'jadlog')->first()->update(['credenciais' => ['token' => 'invalido']]);
    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao), $headers)->assertStatus(502);

    // corrigiu o problema e repetiu
    $seller->credenciais()->where('transportadora', 'jadlog')->first()->update(['credenciais' => ['token' => 'token-jadlog']]);
    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao), $headers)->assertCreated();
});

it('Correios: se o segundo volume falha, o primeiro e cancelado', function () {
    [$seller, $chave] = $this->criarSellerComAsDuas();
    $cotacao = $seller->cotacoes()->create([
        'cep_origem' => '01310100', 'cep_destino' => '20040020',
        'requisicao' => $this->dadosDeCotacao(['volumes' => array_fill(0, 2, ['peso_gramas' => 500, 'altura_cm' => 10, 'largura_cm' => 10, 'comprimento_cm' => 10])]),
        'resultados' => ['opcoes' => [['transportadora' => 'correios', 'servico_codigo' => '03220', 'servico_nome' => 'SEDEX', 'valor_centavos' => 4000, 'prazo_dias' => 2]], 'falhas' => []],
        'expira_em' => now()->addMinutes(15),
    ]);

    \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
    \Illuminate\Support\Facades\Http::fake([
        '*/token/*' => \Illuminate\Support\Facades\Http::response(['token' => 'tk', 'expiraEm' => now('America/Sao_Paulo')->addDay()->format('Y-m-d\TH:i:s')], 201),
        '*/prepostagens/PR1' => \Illuminate\Support\Facades\Http::response('', 204),
        '*/prepostagens' => \Illuminate\Support\Facades\Http::sequence()
            ->push(['id' => 'PR1', 'codigoObjeto' => 'AA111111111BR'], 200)
            ->push('<html>erro</html>', 500),
    ]);

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao, 'correios'), $this->comChave($chave))
        ->assertStatus(502)->assertJsonPath('erro.detalhes.motivo', 'erro_na_transportadora');

    \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/prepostagens/PR1'));
    expect($seller->remessas()->count())->toBe(0);
});

// ---------------------------------------------------------------- consultar

it('consulta a remessa; pedir pela URL de outro seller da 404', function () {
    [$a, $chave, $cotacao] = cenarioDeRemessa($this);
    [$b] = $this->criarSellerComAsDuas('B');

    $id = $this->postJson($this->urlDoSeller($a, '/remessas'), $this->dadosDeRemessa($cotacao), $this->comChave($chave))->json('data.id');

    $this->getJson($this->urlDoSeller($a, "/remessas/{$id}"), $this->auth($chave))->assertOk()->assertJsonPath('data.id', $id);
    $this->getJson($this->urlDoSeller($b, "/remessas/{$id}"), $this->auth($chave))->assertStatus(404)->assertJsonPath('erro.codigo', 'nao_encontrado');
    $this->getJson($this->urlDoSeller($a, '/remessas/nao-e-uuid'), $this->auth($chave))->assertStatus(404);
});

// ---------------------------------------------------------------- despachar

it('despacha uma remessa criada, uma unica vez', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $id = $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao), $this->comChave($chave))->json('data.id');

    $r = $this->postJson("/api/v1/sellers/{$seller->id}/remessas/{$id}/despachar", [], $this->auth($chave))->assertOk()->assertJsonPath('data.status', 'despachada');
    expect($r->json('data.despachada_em'))->not->toBeNull();

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas/{$id}/despachar", [], $this->auth($chave))
        ->assertStatus(409)->assertJsonPath('erro.codigo', 'status_invalido');
});

// ---------------------------------------------------------------- cancelar

it('cancela na Jadlog e nos Correios', function (string $transportadora) {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $id = $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao, $transportadora), $this->comChave($chave))->json('data.id');

    $r = $this->postJson("/api/v1/sellers/{$seller->id}/remessas/{$id}/cancelar", [], $this->auth($chave))->assertOk()->assertJsonPath('data.status', 'cancelada');

    expect($r->json('data.cancelada_em'))->not->toBeNull()
        ->and(ChamadaTransportadora::where('operacao', 'cancelar_remessa')->count())->toBe(1);

    // cancelada nao cancela de novo
    $this->postJson("/api/v1/sellers/{$seller->id}/remessas/{$id}/cancelar", [], $this->auth($chave))->assertStatus(409);
})->with(['jadlog', 'correios']);

it('cancelar uma remessa ja cancelada na transportadora nao e erro', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $id = $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao), $this->comChave($chave))->json('data.id');
    $seller->remessas()->whereKey($id)->update(['id_externo' => '00000000000099']); // o simulador responde "Pedido ja esta cancelado"

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas/{$id}/cancelar", [], $this->auth($chave))->assertOk()->assertJsonPath('data.status', 'cancelada');
});

it('a transportadora pode recusar o cancelamento: a remessa continua como estava', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $id = $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao), $this->comChave($chave))->json('data.id');
    $seller->remessas()->whereKey($id)->update(['id_externo' => '00000000000077']); // o simulador recusa

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas/{$id}/cancelar", [], $this->auth($chave))
        ->assertStatus(422)->assertJsonPath('erro.codigo', 'cancelamento_recusado');

    expect($seller->remessas()->find($id)->status)->toBe(StatusRemessa::Criada);
});

it('so cancela enquanto a remessa esta criada ou despachada', function (string $status, int $esperado) {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $id = $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao), $this->comChave($chave))->json('data.id');
    $seller->remessas()->whereKey($id)->update(['status' => $status]);

    $r = $this->postJson("/api/v1/sellers/{$seller->id}/remessas/{$id}/cancelar", [], $this->auth($chave))->assertStatus($esperado);

    if ($esperado === 409) {
        $r->assertJsonPath('erro.codigo', 'status_invalido');
    }
})->with([
    'criada' => ['criada', 200],
    'despachada' => ['despachada', 200],
    'em transito' => ['em_transito', 409],
    'saiu para entrega' => ['saiu_para_entrega', 409],
    'entregue' => ['entregue', 409],
]);

it('cancelamento com a transportadora fora do ar da 502 e nao muda o status', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $id = $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao), $this->comChave($chave))->json('data.id');
    $seller->credenciais()->where('transportadora', 'jadlog')->first()->update(['credenciais' => ['token' => 'invalido']]);

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas/{$id}/cancelar", [], $this->auth($chave))
        ->assertStatus(502)->assertJsonPath('erro.detalhes.motivo', 'credencial_invalida');

    expect($seller->remessas()->find($id)->status)->toBe(StatusRemessa::Criada);
});

// ---------------------------------------------------------------- etiqueta

it('devolve o PDF da etiqueta (Jadlog: guardado na criacao)', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $id = $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao), $this->comChave($chave))->json('data.id');

    $r = $this->get("/api/v1/sellers/{$seller->id}/remessas/{$id}/etiqueta", $this->auth($chave))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect($r->getContent())->toStartWith('%PDF');
});

it('Correios: a etiqueta e gerada na primeira consulta e reaproveitada depois', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $id = $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao, 'correios'), $this->comChave($chave))->json('data.id');

    $this->get("/api/v1/sellers/{$seller->id}/remessas/{$id}/etiqueta", $this->auth($chave))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get("/api/v1/sellers/{$seller->id}/remessas/{$id}/etiqueta", $this->auth($chave))->assertOk();

    expect(ChamadaTransportadora::where('operacao', 'etiqueta')->count())->toBe(1)
        ->and($seller->remessas()->find($id)->etiqueta_caminho)->not->toBeNull();
    $this->getJson("/api/v1/sellers/{$seller->id}/remessas/{$id}", $this->auth($chave))->assertJsonPath('data.etiqueta_disponivel', true);
});

it('etiqueta indisponivel responde 404 e remessa cancelada responde 409', function () {
    [$seller, $chave, $cotacao] = cenarioDeRemessa($this);
    $id = $this->postJson("/api/v1/sellers/{$seller->id}/remessas", $this->dadosDeRemessa($cotacao), $this->comChave($chave))->json('data.id');

    $seller->remessas()->whereKey($id)->update(['etiqueta_caminho' => null]); // Jadlog nao tem como buscar de novo
    $this->getJson("/api/v1/sellers/{$seller->id}/remessas/{$id}/etiqueta", $this->auth($chave))->assertStatus(404)->assertJsonPath('erro.codigo', 'etiqueta_indisponivel');

    $this->postJson("/api/v1/sellers/{$seller->id}/remessas/{$id}/cancelar", [], $this->auth($chave))->assertOk();
    $this->getJson("/api/v1/sellers/{$seller->id}/remessas/{$id}/etiqueta", $this->auth($chave))->assertStatus(409);
});

// ---------------------------------------------------------------- isolamento

it('um seller nao ve, despacha, cancela nem pega etiqueta de remessa de outro', function () {
    [$a, $chave, $cotacao] = cenarioDeRemessa($this);
    [$b] = $this->criarSellerComAsDuas('B');
    $id = $this->postJson($this->urlDoSeller($a, '/remessas'), $this->dadosDeRemessa($cotacao), $this->comChave($chave))->json('data.id');

    foreach ([['get', ''], ['post', '/despachar'], ['post', '/cancelar'], ['get', '/etiqueta']] as [$metodo, $sufixo]) {
        $this->json($metodo, $this->urlDoSeller($b, "/remessas/{$id}{$sufixo}"), [], $this->auth($chave))
            ->assertStatus(404)->assertJsonPath('erro.codigo', 'nao_encontrado');
    }

    // e nada mudou para o dono
    $this->getJson($this->urlDoSeller($a, "/remessas/{$id}"), $this->auth($chave))->assertJsonPath('data.status', 'criada');
});
