<?php

declare(strict_types=1);

use App\Models\CredencialTransportadora;
use App\Models\Remessa;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('toda tabela tem RLS habilitado (trigger do Postgres)', function () {
    $semRls = DB::select("
        select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public' and c.relkind in ('r','p') and not c.relrowsecurity
    ");

    expect(array_column($semRls, 'relname'))->toBe([]);
});

it('o usuario da aplicacao nao e superuser e nao altera o schema', function () {
    expect(DB::selectOne('show is_superuser')->is_superuser)->toBe('off');

    expect(fn () => DB::statement('create table _nao_pode (i int)'))->toThrow(QueryException::class);
});

it('seller_id e a primeira coluna dos indices compostos das tabelas de seller', function () {
    $indices = DB::select("
        select i.relname as indice,
               (select a.attname from pg_attribute a where a.attrelid = t.oid and a.attnum = ix.indkey[0]) as primeira
        from pg_index ix
        join pg_class t on t.oid = ix.indrelid
        join pg_class i on i.oid = ix.indexrelid
        join pg_namespace n on n.oid = t.relnamespace
        where n.nspname = 'public' and ix.indnatts > 1
          and exists (select 1 from pg_attribute a where a.attrelid = t.oid and a.attname = 'seller_id')
    ");

    $errados = array_column(array_filter($indices, fn ($i) => $i->primeira !== 'seller_id'), 'indice');

    expect($errados)->toBe([]);
});

it('remessas tem os indices exigidos', function () {
    $defs = collect(DB::select("select indexdef from pg_indexes where tablename = 'remessas'"))->pluck('indexdef')->implode("\n");

    expect($defs)->toContain('UNIQUE INDEX remessas_seller_id_idempotency_key_unique')
        ->toContain('(seller_id, idempotency_key)')
        ->toContain('(codigo_rastreio)')
        ->toContain('(seller_id, status)');
});

it('mesma idempotency_key no mesmo seller e recusada pelo banco, mas outro seller pode usar', function () {
    [$a] = $this->criarSeller('A');
    [$b] = $this->criarSeller('B');
    $remessa = $this->criarRemessa($a, $this->criarCotacao($a));

    $dados = fn ($seller, $cotacao) => [
        'tipo' => 'envio', 'cotacao_id' => $cotacao->id, 'idempotency_key' => $remessa->idempotency_key,
        'hash_requisicao' => str_repeat('b', 64), 'transportadora' => 'jadlog', 'servico_codigo' => '3',
        'status' => 'criada', 'valor_frete_centavos' => 1, 'remetente' => [], 'destinatario' => [], 'volumes' => [],
    ];

    $cotacaoA2 = $this->criarCotacao($a);
    $cotacaoB = $this->criarCotacao($b);

    // Outro seller pode usar a mesma chave...
    expect($b->remessas()->create($dados($b, $cotacaoB)))->not->toBeNull();

    // ...o mesmo seller nao (este teste termina aqui: depois de um erro o Postgres aborta a transacao).
    expect(fn () => $a->remessas()->create($dados($a, $cotacaoA2)))->toThrow(QueryException::class);
});

it('o mesmo evento de rastreio nao entra duas vezes', function () {
    [$a] = $this->criarSeller('A');
    $remessa = $this->criarRemessa($a, $this->criarCotacao($a));
    $quando = now()->startOfSecond();

    $remessa->eventos()->create(['status' => 'postada', 'ocorrido_em' => $quando]);

    expect(fn () => $remessa->eventos()->create(['status' => 'postada', 'ocorrido_em' => $quando]))->toThrow(QueryException::class);
});

it('jsonb nos payloads e timestamps com fuso', function () {
    $colunas = collect(DB::select("select table_name, column_name, data_type from information_schema.columns where table_schema = 'public'"));

    expect($colunas->where('table_name', 'cotacoes')->where('column_name', 'resultados')->first()->data_type)->toBe('jsonb')
        ->and($colunas->where('column_name', 'created_at')->pluck('data_type')->unique()->all())->toBe(['timestamp with time zone']);
});

it('credenciais e dados pessoais ficam criptografados no banco', function () {
    [$seller] = $this->criarSeller();

    $credencial = $seller->clienteApi->credenciais()->create(['transportadora' => 'jadlog', 'credenciais' => ['token' => 'TOKEN-SECRETO-123']]);
    $bruto = DB::table('credenciais_transportadoras')->where('id', $credencial->id)->first();

    expect($bruto->credenciais)->not->toContain('TOKEN-SECRETO-123')
        ->and($credencial->fresh()->credenciais['token'])->toBe('TOKEN-SECRETO-123')
        ->and(json_encode($credencial->fresh()->toArray()))->not->toContain('TOKEN-SECRETO-123');

    $remessa = $this->criarRemessa($seller, $this->criarCotacao($seller));
    $linha = DB::table('remessas')->where('id', $remessa->id)->first();

    expect($linha->destinatario)->not->toContain('Maria da Silva')
        ->and(Remessa::find($remessa->id)->destinatario['nome'])->toBe('Maria da Silva');
});
