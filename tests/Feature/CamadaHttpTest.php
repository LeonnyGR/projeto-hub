<?php

declare(strict_types=1);

use App\Http\Requests\ApiFormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

it('GET /up responde sem consultar o banco', function () {
    $consultas = 0;
    DB::listen(function () use (&$consultas) {
        $consultas++;
    });

    $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'ok']);

    expect($consultas)->toBe(0);
});

it('manda cabecalhos de seguranca e request_id em toda resposta', function () {
    [$cliente, $chave] = $this->criarCliente();

    $r = $this->getJson('/api/v1/_teste/eu', $this->auth($chave));

    $r->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    expect($r->headers->get('Cache-Control'))->toContain('no-store')
        ->and($r->headers->get('X-Request-Id'))->toMatch('/^[a-f0-9]{32}$/')
        ->and($r->headers->has('X-Powered-By'))->toBeFalse()
        ->and($r->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('manda HSTS quando a requisicao e HTTPS', function () {
    $this->get('https://localhost/up')->assertHeader('Strict-Transport-Security');
});

it('usa o request_id do nginx e ignora valores estranhos vindos do cliente', function () {
    $id = str_repeat('ab', 16);
    $this->getJson('/up', ['X-Request-Id' => $id])->assertHeader('X-Request-Id', $id);

    $r = $this->getJson('/up', ['X-Request-Id' => 'injecao<script>']);
    expect($r->headers->get('X-Request-Id'))->not->toContain('script')->toMatch('/^[a-f0-9]{32}$/');
});

it('erros seguem o formato padrao, com request_id e sem detalhes internos', function () {
    $r = $this->getJson('/api/v1/nao-existe');

    $r->assertStatus(404)
        ->assertJsonStructure(['erro' => ['codigo', 'mensagem'], 'request_id'])
        ->assertJsonPath('erro.codigo', 'nao_encontrado');
    expect($r->json('request_id'))->toBe($r->headers->get('X-Request-Id'))
        ->and($r->getContent())->not->toContain('route')->not->toContain('vendor');

    $this->postJson('/up')->assertStatus(405)->assertJsonPath('erro.codigo', 'metodo_nao_permitido')->assertHeader('Allow');
});

it('erro inesperado vira 500 generico, sem stack trace nem mensagem interna', function () {
    Route::middleware('api')->get('/api/v1/_teste/explode', fn () => throw new RuntimeException('segredo-interno: SELECT * FROM clientes'));

    $r = $this->getJson('/api/v1/_teste/explode');

    $r->assertStatus(500)->assertJsonPath('erro.codigo', 'erro_interno');
    expect($r->getContent())->not->toContain('segredo-interno')->not->toContain('SELECT')->not->toContain('trace');
});

it('erro de banco nao mostra SQL nem valores na resposta', function () {
    Route::middleware('api')->get('/api/v1/_teste/sql', fn () => DB::select('select * from tabela_que_nao_existe where x = ?', ['maria@exemplo.com']));

    $r = $this->getJson('/api/v1/_teste/sql');

    $r->assertStatus(500);
    expect($r->getContent())->not->toContain('tabela_que_nao_existe')->not->toContain('maria@exemplo.com');
});

it('Form Request rejeita campo desconhecido com 422 padronizado', function () {
    $request = new class extends ApiFormRequest {
        public function rules(): array
        {
            return ['nome' => ['required', 'string', 'max:10'], 'itens' => ['array'], 'itens.*.peso' => ['integer']];
        }
    };
    app()->bind($request::class, fn () => $request::createFrom(request(), new $request()));
    Route::middleware('api')->post('/api/v1/_teste/form', fn () => response()->json(app($request::class)->validated()));

    $this->postJson('/api/v1/_teste/form', ['nome' => 'ok', 'itens' => [['peso' => 1]]])->assertOk();

    $this->postJson('/api/v1/_teste/form', ['nome' => 'ok', 'is_admin' => true])
        ->assertStatus(422)
        ->assertJsonPath('erro.codigo', 'validacao_falhou')
        ->assertJsonPath('erro.detalhes.is_admin.0', 'Campo desconhecido.');
});

it('nao libera CORS para origens desconhecidas', function () {
    $r = $this->getJson('/api/v1/nao-existe', ['Origin' => 'https://evil.example']);

    expect($r->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});
