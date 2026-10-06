<?php

declare(strict_types=1);

use App\Support\Mascaramento;

it('oculta segredos pelo nome do campo, em qualquer profundidade', function () {
    $saida = Mascaramento::array([
        'Authorization' => 'Bearer abc.def',
        'token' => 'eyJhbGciOi',
        'senha' => 'minha-senha',
        'password' => 'x',
        'api_key' => 'k',
        'aninhado' => ['access_token' => 'zzz', 'ok' => 'visivel'],
        'nome' => 'Maria',
    ]);

    expect($saida['Authorization'])->toBe(Mascaramento::OCULTO)
        ->and($saida['token'])->toBe(Mascaramento::OCULTO)
        ->and($saida['senha'])->toBe(Mascaramento::OCULTO)
        ->and($saida['password'])->toBe(Mascaramento::OCULTO)
        ->and($saida['api_key'])->toBe(Mascaramento::OCULTO)
        ->and($saida['aninhado']['access_token'])->toBe(Mascaramento::OCULTO)
        ->and($saida['aninhado']['ok'])->toBe('visivel')
        ->and($saida['nome'])->toBe('Maria');
});

it('mascara CPF, CNPJ, telefone e e-mail pelo nome do campo', function () {
    $saida = Mascaramento::array([
        'cnpjCpf' => '12345678000195',
        'cpf' => '123.456.789-09',
        'fone' => '11 99999-8888',
        'cel' => '(11) 999998888',
        'email' => 'maria.silva@exemplo.com.br',
    ]);

    expect($saida['cnpjCpf'])->toBe('************95')
        ->and($saida['cpf'])->toBe('*********09')
        ->and($saida['fone'])->toBe('*********88')
        ->and($saida['cel'])->toBe('*********88')
        ->and($saida['email'])->toBe('m***@exemplo.com.br');
});

it('mascara dados sensiveis em texto livre e XML', function () {
    $texto = 'cliente maria@exemplo.com cpf 123.456.789-09 cnpj 12.345.678/0001-95 fone (11) 99999-8888 '
        .'Authorization: Bearer abc.def-123 senha=hunter2 <token>segredo123</token>';

    expect(Mascaramento::texto($texto))
        ->not->toContain('maria@exemplo.com')
        ->not->toContain('123.456.789-09')
        ->not->toContain('12.345.678/0001-95')
        ->not->toContain('99999-8888')
        ->not->toContain('abc.def-123')
        ->not->toContain('hunter2')
        ->not->toContain('segredo123')
        ->toContain('m***@exemplo.com');
});

it('mascara um corpo JSON mantendo a estrutura', function () {
    $json = json_encode(['des' => ['cnpjCpf' => '12345678909', 'email' => 'a@b.com'], 'token' => 'xyz', 'peso' => 2.5]);

    $saida = json_decode((string) Mascaramento::texto($json), true);

    expect($saida['token'])->toBe(Mascaramento::OCULTO)
        ->and($saida['des']['cnpjCpf'])->toBe('*********09')
        ->and($saida['des']['email'])->toBe('a***@b.com')
        ->and($saida['peso'])->toBe(2.5);
});

it('mascara cabecalhos sensiveis', function () {
    $saida = Mascaramento::cabecalhos(['Authorization' => ['Bearer x'], 'Cookie' => 'a=b', 'Content-Type' => 'application/json']);

    expect($saida['Authorization'])->toBe(Mascaramento::OCULTO)
        ->and($saida['Cookie'])->toBe(Mascaramento::OCULTO)
        ->and($saida['Content-Type'])->toBe('application/json');
});

it('tira a query string da URL', function () {
    expect(Mascaramento::url('https://x.com/prazo/v1/nacional/03220?cepOrigem=01001000&token=abc#f'))
        ->toBe('https://x.com/prazo/v1/nacional/03220');
});

it('mantem nulos e vazios como estao', function () {
    expect(Mascaramento::texto(null))->toBeNull()
        ->and(Mascaramento::texto(''))->toBe('')
        ->and(Mascaramento::array(['a' => null, 'email' => '']))->toBe(['a' => null, 'email' => '']);
});
