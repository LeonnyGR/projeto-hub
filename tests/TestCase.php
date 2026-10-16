<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\PapelUsuario;
use App\Models\Cliente;
use App\Models\Cotacao;
use App\Models\Remessa;
use App\Models\Usuario;
use App\Models\UsuarioAplicacao;
use App\Services\ChaveApiService;
use App\Services\ClienteService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Redis limpo a cada teste (rate limit, cache).
        Redis::connection('default')->flushdb();

        // Rota de teste: serve para testar autenticacao e limite de requisicoes sem depender das rotas de negocio.
        Route::middleware(['api', 'auth.cliente', 'throttle:cotacoes'])
            ->get('/api/v1/_teste/eu', fn (Request $request) => response()->json(['cliente_id' => $request->attributes->get('cliente')->id]));
    }

    /**
     * Cria um cliente com um dono (adm_tenant) e uma chave de API. Devolve [Cliente, 'texto da chave de API'].
     * O texto da chave vai direto em $this->auth().
     */
    public function criarCliente(string $nome = 'Cliente Teste', ?string $cnpj = null): array
    {
        [$cliente] = app(ClienteService::class)->criar($nome, $cnpj ?? $this->cnpjNovo(), [
            'nome' => 'Dono de '.$nome,
            'email' => 'dono-'.uniqid().'@exemplo.com.br',
            'password' => 'senha-do-dono-123',
        ]);
        [, $chave] = app(ChaveApiService::class)->criar($cliente, 'teste');

        return [$cliente, $chave];
    }

    /** Cria um usuario do portal (senha 'senha-de-teste-123'). Papel: adm_tenant ou operador_tenant. */
    public function criarUsuario(Cliente $cliente, PapelUsuario $papel = PapelUsuario::OperadorTenant, string $email = null): Usuario
    {
        return $cliente->usuarios()->create([
            'nome' => 'Usuario '.$papel->value,
            'email' => $email ?? uniqid().'@exemplo.com.br',
            'password' => 'senha-de-teste-123',
            'papel' => $papel,
            'ativo' => true,
        ]);
    }

    /** Libera ao usuario o que o mapa diz: ['OPE_02' => ['ver', 'etiqueta']]. Troca o que ele tinha. */
    public function liberar(Usuario $usuario, array $mapa): Usuario
    {
        UsuarioAplicacao::where('usuario_id', $usuario->id)->delete();
        foreach ($mapa as $aplicacao => $acoes) {
            UsuarioAplicacao::create(['usuario_id' => $usuario->id, 'aplicacao_codigo' => $aplicacao, 'acoes' => $acoes]);
        }

        return $usuario;
    }

    /** O "dia a dia" de antes das permissoes: cotar, remessas, devolucoes e ver transportadoras (sem editar, sem ADM). */
    public function liberarDiaADia(Usuario $usuario): Usuario
    {
        $mapa = [];
        foreach (config('permissoes.modulos') as $codigo => $modulo) {
            foreach ($modulo['aplicacoes'] as $app => $dados) {
                if (in_array($codigo, ['COT', 'OPE', 'REV'], true)) {
                    $mapa[$app] = $dados['acoes'];
                }
            }
        }

        return $this->liberar($usuario, $mapa + ['TRA_01' => ['ver']]);
    }

    /** CNPJ valido e diferente a cada chamada (os clientes tem CNPJ unico). */
    public function cnpjNovo(): string
    {
        $base = str_pad((string) random_int(10_000_000, 99_999_999), 8, '0', STR_PAD_LEFT).'0001';

        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $pesos) {
            $soma = 0;
            foreach ($pesos as $i => $peso) {
                $soma += (int) $base[$i] * $peso;
            }
            $resto = $soma % 11;
            $base .= $resto < 2 ? 0 : 11 - $resto;
        }

        return $base;
    }

    /** Header de autenticacao com a chave de API. */
    public function auth(string $chave): array
    {
        return ['Authorization' => 'Bearer '.$chave];
    }

    /** Tomador do frete (quem paga) nos contratos de teste. */
    public function tomadorDoContrato(): array
    {
        return [
            'nome' => 'Bartofil Comercio Ltda', 'documento' => '12345678000195', 'telefone' => '1133334444', 'email' => 'frete@bartofil.com.br',
            'endereco' => ['cep' => '01001000', 'logradouro' => 'Praca da Se', 'numero' => '1', 'bairro' => 'Se', 'cidade' => 'Sao Paulo', 'uf' => 'SP'],
        ];
    }

    /** Contrato do cliente com a Jadlog (3 modalidades). */
    public function configurarJadlog(Cliente $cliente, string $token = 'token-jadlog'): void
    {
        $cliente->credenciais()->updateOrCreate(['transportadora' => 'jadlog'], [
            'ativa' => true,
            'credenciais' => ['token' => $token],
            'configuracao' => [
                'tomador' => $this->tomadorDoContrato(),
                'cod_cliente' => '000123',
                'servicos' => [
                    ['codigo' => '3', 'nome' => 'Package'],
                    ['codigo' => '0', 'nome' => 'Expresso'],
                    ['codigo' => '5', 'nome' => 'Economico'],
                ],
            ],
        ]);
    }

    /** Contrato do cliente com os Correios (SEDEX e PAC). */
    public function configurarCorreios(Cliente $cliente, string $usuario = 'usuario-correios'): void
    {
        $cliente->credenciais()->updateOrCreate(['transportadora' => 'correios'], [
            'ativa' => true,
            'credenciais' => ['usuario' => $usuario, 'senha' => 'senha-secreta-dos-correios', 'cartao_postagem' => '0012345678'],
            'configuracao' => [
                'servicos' => [
                    ['codigo' => '03220', 'nome' => 'SEDEX'],
                    ['codigo' => '04669', 'nome' => 'PAC'],
                ],
            ],
        ]);
    }

    /** Cliente com Jadlog e Correios configurados. Devolve [Cliente, 'chave de API']. */
    public function criarClienteComAsDuas(string $nome = 'Loja'): array
    {
        [$cliente, $credenciais] = $this->criarCliente($nome);
        $this->configurarJadlog($cliente);
        $this->configurarCorreios($cliente);

        return [$cliente, $credenciais];
    }

    /** Cotacao salva direto no banco, com opcoes prontas (para testar remessas em qualquer CEP de destino). */
    public function criarCotacaoComOpcoes(Cliente $cliente, string $cepDestino = '20040020'): Cotacao
    {
        return $cliente->cotacoes()->create([
            'cep_origem' => '01310100',
            'cep_destino' => $cepDestino,
            'requisicao' => $this->dadosDeCotacao(['cep_destino' => $cepDestino]),
            'resultados' => [
                'opcoes' => [
                    ['transportadora' => 'jadlog', 'servico_codigo' => '3', 'servico_nome' => 'Package', 'valor_centavos' => 1460, 'prazo_dias' => 6],
                    ['transportadora' => 'correios', 'servico_codigo' => '03220', 'servico_nome' => 'SEDEX', 'valor_centavos' => 2140, 'prazo_dias' => 3],
                ],
                'falhas' => [],
            ],
            'expira_em' => now()->addMinutes(15),
        ]);
    }

    /** Remessa pronta para enviar em POST /remessas (CEPs iguais aos da cotacao). */
    public function dadosDeRemessa(Cotacao $cotacao, string $transportadora = 'jadlog', array $mudar = []): array
    {
        $servico = ['jadlog' => '3', 'correios' => '03220'][$transportadora];

        return array_replace_recursive([
            'cotacao_id' => $cotacao->id,
            'transportadora' => $transportadora,
            'servico_codigo' => $servico,
            'numero_pedido' => 'PED-1001',
            'remetente' => [
                'nome' => 'Loja do Joao Ltda', 'documento' => '11222333000181', 'telefone' => '1133334444', 'email' => 'contato@loja.com.br',
                'endereco' => ['cep' => $cotacao->cep_origem, 'logradouro' => 'Avenida Paulista', 'numero' => '1000', 'complemento' => 'Sala 5', 'bairro' => 'Bela Vista', 'cidade' => 'Sao Paulo', 'uf' => 'SP'],
            ],
            'destinatario' => [
                'nome' => 'Maria da Silva', 'documento' => '52998224725', 'telefone' => '21987654321', 'email' => 'maria@exemplo.com',
                'endereco' => ['cep' => $cotacao->cep_destino, 'logradouro' => 'Rua da Assembleia', 'numero' => '10', 'bairro' => 'Centro', 'cidade' => 'Rio de Janeiro', 'uf' => 'RJ'],
            ],
        ], $mudar);
    }

    /** Header Idempotency-Key + autenticacao. */
    public function comChave(string $credenciais, ?string $idempotencia = null): array
    {
        return $this->auth($credenciais) + ['Idempotency-Key' => $idempotencia ?? 'chave-'.uniqid()];
    }

    /** Corpo valido de cotacao (1 volume). */
    public function dadosDeCotacao(array $mudar = []): array
    {
        return array_merge([
            'cep_origem' => '01310100',
            'cep_destino' => '20040020',
            'valor_declarado_centavos' => 15000,
            'volumes' => [['peso_gramas' => 1200, 'altura_cm' => 10, 'largura_cm' => 20, 'comprimento_cm' => 30]],
        ], $mudar);
    }

    public function criarCotacao(Cliente $cliente): Cotacao
    {
        return $cliente->cotacoes()->create([
            'cep_origem' => '01310100',
            'cep_destino' => '20040020',
            'requisicao' => ['volumes' => []],
            'resultados' => [],
            'expira_em' => now()->addMinutes(15),
        ]);
    }

    public function criarRemessa(Cliente $cliente, Cotacao $cotacao): Remessa
    {
        return $cliente->remessas()->create([
            'tipo' => 'envio',
            'cotacao_id' => $cotacao->id,
            'idempotency_key' => 'chave-'.uniqid(),
            'hash_requisicao' => str_repeat('a', 64),
            'transportadora' => 'jadlog',
            'servico_codigo' => '3',
            'status' => 'criada',
            'valor_frete_centavos' => 1500,
            'remetente' => ['nome' => 'Loja'],
            'destinatario' => ['nome' => 'Maria da Silva'],
            'volumes' => [['peso_gramas' => 500]],
        ]);
    }
}
