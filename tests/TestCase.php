<?php

declare(strict_types=1);

namespace Tests;

use App\Models\ClienteApi;
use App\Models\Cotacao;
use App\Models\Remessa;
use App\Models\Seller;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

abstract class TestCase extends BaseTestCase
{
    /** Cliente da API (o B2C) deste teste e a chave dele. Criados na primeira vez que um teste precisa. */
    private ?ClienteApi $clienteDoTeste = null;

    private ?string $chaveDoCliente = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Redis limpo a cada teste (rate limit, cache).
        Redis::connection('default')->flushdb();

        // Rotas de teste: servem para testar autenticacao, seller da URL e limite de requisicoes
        // sem depender das rotas de negocio.
        Route::middleware(['api', 'auth.cliente', 'throttle:cotacoes'])
            ->get('/api/v1/_teste/eu', fn (Request $request) => response()->json(['cliente_id' => $request->attributes->get('cliente_api')->id]));
        Route::middleware(['api', 'auth.cliente', 'seller.da.url', 'throttle:cotacoes'])
            ->get('/api/v1/sellers/{seller}/_teste/eu', fn (Request $request) => response()->json(['seller_id' => $request->attributes->get('seller')->id]));
    }

    /** Cria um cliente da API (o sistema que chama, ex.: B2C). Devolve [ClienteApi, 'chave em texto']. */
    public function criarCliente(string $nome = 'B2C Teste'): array
    {
        $resultado = app(ApiKeyService::class)->criarCliente($nome);

        return [$resultado['cliente'], $resultado['chave']];
    }

    /**
     * Cria um seller do cliente da API do teste (ou de $cliente). Devolve [Seller, 'chave de API'].
     * A chave e do cliente e e a MESMA para todos os sellers do teste (como na vida real:
     * o B2C chamando em nome de varios sellers).
     */
    public function criarSeller(string $nome = 'Seller Teste', ?ClienteApi $cliente = null): array
    {
        if ($this->clienteDoTeste === null) {
            [$this->clienteDoTeste, $this->chaveDoCliente] = $this->criarCliente();
        }

        $cliente ??= $this->clienteDoTeste;

        return [$cliente->sellers()->create(['nome' => $nome, 'cep_origem' => '01310100', 'ativo' => true]), $this->chaveDoCliente];
    }

    /** URL de uma rota do seller: /api/v1/sellers/{id}/caminho */
    public function urlDoSeller(Seller $seller, string $caminho = ''): string
    {
        return '/api/v1/sellers/'.$seller->id.$caminho;
    }

    /** Header de autenticacao para a chave. */
    public function auth(string $chave): array
    {
        return ['Authorization' => 'Bearer '.$chave];
    }

    /** Tomador do frete (quem paga) nos contratos de teste: o B2C. Diferente do seller, de proposito. */
    public function tomadorDoContrato(): array
    {
        return [
            'nome' => 'Bartofil Comercio Ltda', 'documento' => '12345678000195', 'telefone' => '1133334444', 'email' => 'frete@bartofil.com.br',
            'endereco' => ['cep' => '01001000', 'logradouro' => 'Praca da Se', 'numero' => '1', 'bairro' => 'Se', 'cidade' => 'Sao Paulo', 'uf' => 'SP'],
        ];
    }

    /** Contrato do B2C com a Jadlog (3 modalidades). O contrato e do cliente da API: vale para todos os sellers dele. */
    public function configurarJadlog(Seller $seller, string $token = 'token-jadlog'): void
    {
        $seller->clienteApi->credenciais()->updateOrCreate(['transportadora' => 'jadlog'], [
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

    /** Contrato do B2C com os Correios (SEDEX e PAC). */
    public function configurarCorreios(Seller $seller, string $usuario = 'usuario-correios'): void
    {
        $seller->clienteApi->credenciais()->updateOrCreate(['transportadora' => 'correios'], [
            'ativa' => true,
            'credenciais' => ['usuario' => $usuario, 'senha' => 'senha', 'cartao_postagem' => '0012345678'],
            'configuracao' => [
                'servicos' => [
                    ['codigo' => '03220', 'nome' => 'SEDEX'],
                    ['codigo' => '04669', 'nome' => 'PAC'],
                ],
            ],
        ]);
    }

    /** Seller com Jadlog e Correios configurados. Devolve [Seller, 'chave']. */
    public function criarSellerComAsDuas(string $nome = 'Loja'): array
    {
        [$seller, $chave] = $this->criarSeller($nome);
        $this->configurarJadlog($seller);
        $this->configurarCorreios($seller);

        return [$seller, $chave];
    }

    /** Cotacao salva direto no banco, com opcoes prontas (para testar remessas em qualquer CEP de destino). */
    public function criarCotacaoComOpcoes(Seller $seller, string $cepDestino = '20040020'): Cotacao
    {
        return $seller->cotacoes()->create([
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
                'nome' => 'Loja do Seller Ltda', 'documento' => '11222333000181', 'telefone' => '1133334444', 'email' => 'contato@loja.com.br',
                'endereco' => ['cep' => $cotacao->cep_origem, 'logradouro' => 'Avenida Paulista', 'numero' => '1000', 'complemento' => 'Sala 5', 'bairro' => 'Bela Vista', 'cidade' => 'Sao Paulo', 'uf' => 'SP'],
            ],
            'destinatario' => [
                'nome' => 'Maria da Silva', 'documento' => '52998224725', 'telefone' => '21987654321', 'email' => 'maria@exemplo.com',
                'endereco' => ['cep' => $cotacao->cep_destino, 'logradouro' => 'Rua da Assembleia', 'numero' => '10', 'bairro' => 'Centro', 'cidade' => 'Rio de Janeiro', 'uf' => 'RJ'],
            ],
        ], $mudar);
    }

    /** Header Idempotency-Key + autenticacao. */
    public function comChave(string $chaveApi, ?string $idempotencia = null): array
    {
        return $this->auth($chaveApi) + ['Idempotency-Key' => $idempotencia ?? 'chave-'.uniqid()];
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

    public function criarCotacao(Seller $seller): Cotacao
    {
        return $seller->cotacoes()->create([
            'cep_origem' => '01310100',
            'cep_destino' => '20040020',
            'requisicao' => ['volumes' => []],
            'resultados' => [],
            'expira_em' => now()->addMinutes(15),
        ]);
    }

    public function criarRemessa(Seller $seller, Cotacao $cotacao): Remessa
    {
        return $seller->remessas()->create([
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
