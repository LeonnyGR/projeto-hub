# Guia do código: da chave de API até as devoluções

Para quem conhece PHP e Laravel o suficiente para ler um Controller, mas quer entender **como as peças se encaixam**.
Cada trecho tem um link para o arquivo. Abra o arquivo ao lado enquanto lê.

> Os números de linha dos links (`#L38`) apontam para o início de cada função. Se o arquivo mudar, a função continua
> fácil de achar pelo nome.

---

## Índice

1. [O caminho de uma requisição](#1-o-caminho-de-uma-requisição)
2. [Injeção de dependência (leia antes de continuar)](#2-injeção-de-dependência-leia-antes-de-continuar)
3. [`routes/api.php`: as rotas](#3-routesapiphp-as-rotas)
4. [`bootstrap/app.php`: middlewares e erros](#4-bootstrapappphp-middlewares-e-erros)
5. [Passo 1: a chave de API (quem está chamando)](#5-passo-1-a-chave-de-api-quem-está-chamando)
6. [Passo 2: o seller da URL](#6-passo-2-o-seller-da-url)
7. [Controller, Form Request, Service e Resource](#7-controller-form-request-service-e-resource)
8. [Cotação: falando com as transportadoras](#8-cotação-falando-com-as-transportadoras)
9. [Cadastro de sellers e das credenciais](#9-cadastro-de-sellers-e-das-credenciais)
10. [Remessa: criar sem duplicar (idempotência)](#10-remessa-criar-sem-duplicar-idempotência)
11. [Despachar, cancelar e etiqueta](#11-despachar-cancelar-e-etiqueta)
12. [Devolução](#12-devolução)
13. [Como os erros chegam ao cliente](#13-como-os-erros-chegam-ao-cliente)
14. [O simulador (modo mock)](#14-o-simulador-modo-mock)
15. [Como os testes funcionam](#15-como-os-testes-funcionam)
16. [Exercício: siga um `POST /remessas`](#16-exercício-siga-um-post-remessas)

---

## 1. O caminho de uma requisição

Tudo o que acontece quando o B2C chama `POST /api/v1/sellers/{id}/cotacoes`:

```
  B2C ──HTTP──► nginx ──► php-fpm ──► public/index.php ──► bootstrap/app.php
                                                                 │
        middlewares GLOBAIS (valem para toda rota)               ▼
        RequestId → ForcaJson → ... → CabecalhosDeSeguranca
                                                                 │
        routes/api.php escolhe a rota e seus middlewares         ▼
        AutenticaCliente → CarregaSeller → throttle (limite)
                                                                 │
        Form Request valida o corpo                              ▼
        Controller (fino) ──► Service (regra de negócio) ──► Model (banco) / Transportadoras (HTTP)
                                                                 │
        Resource formata a resposta  ◄───────────────────────────┘
        (se algo lançar exceção: RenderizaExcecao devolve o erro em JSON)
```

Regra de ouro do projeto: **o Controller não tem regra de negócio**. Ele recebe, chama um Service e devolve. A lógica fica nos Services.

---

## 2. Injeção de dependência (leia antes de continuar)

Este conceito aparece em quase todo arquivo, então vale entender com calma.

### O problema que ela resolve
O `CotacaoController` precisa do `CotacaoService`, que precisa do `ClienteHttp`. Sem DI, seria assim:

```php
$http = new ClienteHttp();
$service = new CotacaoService($http);        // o controller teria que saber montar tudo
$cotacao = $service->cotar($seller, $dados);
```

Cada classe teria que saber **como criar** as outras. Se amanhã o `ClienteHttp` pedir um parâmetro novo, você corrige em todo lugar.

### Como o Laravel resolve
O Laravel tem um **container de serviços**: ele olha o **tipo** declarado nos parâmetros e **cria o objeto sozinho**
(inclusive os objetos de que ele depende, em cadeia). Veja em [CotacaoController.php:15](../app/Http/Controllers/CotacaoController.php#L15):

```php
public function store(CotacaoRequest $request, CotacaoService $service): JsonResponse
```

Você nunca escreve `new CotacaoService(...)`. O Laravel faz esta cadeia sozinho:

```
Laravel vai chamar CotacaoController::store
 ├─ precisa de CotacaoRequest   → cria, e VALIDA o corpo antes de entrar no método
 └─ precisa de CotacaoService   → olha o construtor de CotacaoService:
        __construct(ClienteHttp $http)   → cria ClienteHttp e entrega
```

O construtor do service ([CotacaoService.php:23](../app/Services/CotacaoService.php#L23)):

```php
public function __construct(private readonly ClienteHttp $http)
{
}
```

Dois detalhes de sintaxe que confundem no começo:
- `private readonly ClienteHttp $http` no construtor é uma **abreviação** (PHP 8): declara a propriedade `$this->http`, atribui o valor e a deixa somente-leitura. Equivale a escrever a propriedade e o `$this->http = $http;` à mão.
- O tipo (`ClienteHttp`) é o que o Laravel usa para saber **o que** entregar. Por isso o tipo é obrigatório.

### Onde a DI aparece no projeto
| Onde | Como |
|---|---|
| Middleware | [AutenticaCliente.php:25](../app/Http/Middleware/AutenticaCliente.php#L25): o construtor recebe `ApiKeyService` |
| Controller | parâmetros do método: `CotacaoService $service`, `RemessaService $service`... |
| Service | construtor: `CotacaoService` recebe `ClienteHttp`; `ConsultasService` recebe `ViaCep` |
| Form Request | declarado como parâmetro do método do controller: o Laravel o cria **e roda as validações antes** de chamar o método |
| Comando artisan | parâmetro de `handle()`: `handle(SellerService $service)` |

### Onde NÃO usamos DI (e por quê)
Em [Transportadoras.php:27](../app/Transportadoras/Transportadoras.php#L27) criamos a classe da transportadora "na mão":

```php
$classe = self::LISTA[$credencial->transportadora];          // 'jadlog' → Jadlog::class
return new $classe($credencial, app(ClienteHttp::class), $sellerId);
```

Aqui o container não ajuda: **a classe só é conhecida em tempo de execução** (depende de qual credencial veio) e o construtor recebe
um dado que não é serviço (`$credencial`). Então usamos `new` e pedimos o `ClienteHttp` ao container com `app(ClienteHttp::class)`.
`app(X::class)` é "container, me entregue um X". É o jeito manual da mesma coisa.

### Por que isso importa
- **Testar**: nos testes podemos trocar o `ClienteHttp` por um falso sem mexer no service.
- **Acoplamento**: o service diz "preciso de algo que faça HTTP"; não decide como criá-lo.

> O container só consegue criar o que tem construtor com tipos que ele sabe resolver. Se um parâmetro for `string $nome` sem valor padrão, ele não sabe o que passar e dá erro.

---

## 3. `routes/api.php`: as rotas

Arquivo: [routes/api.php](../routes/api.php). Leia de cima para baixo. Ele tem três níveis:

```php
Route::middleware('auth.cliente')->group(function () {          // L16: TUDO aqui exige a chave de API
    Route::get('enderecos/{cep}', ...);                          // não depende de seller
    Route::post('sellers', ...);                                 // cadastro
    Route::prefix('sellers/{seller}')                            // L29: tudo abaixo é "de um seller"
        ->whereUuid('seller')
        ->middleware('seller.da.url')
        ->group(function () {
            Route::post('cotacoes', [CotacaoController::class, 'store'])->middleware('throttle:cotacoes');
            ...
        });
});
```

Pontos para entender:

- **`Route::middleware(...)->group(...)`**: aplica o middleware a todas as rotas de dentro. Os grupos se aninham: uma rota de seller passa por `auth.cliente` **e depois** por `seller.da.url`.
- **`'auth.cliente'` e `'seller.da.url'` são apelidos** (aliases) de classes. Quem define o apelido é o [bootstrap/app.php:40](../bootstrap/app.php#L40).
- **`/api/v1`** não aparece no arquivo: é adicionado automaticamente em [bootstrap/app.php](../bootstrap/app.php) (`apiPrefix: 'api/v1'`).
- **`{seller}` e `{id}`** são parâmetros da URL. `->whereUuid('id')` só deixa a rota casar se for um UUID: `/remessas/abc` dá 404 sem sequer chegar no controller.
- **`[CotacaoController::class, 'store']`** = "chame o método `store` desta classe". Por isso o controller é criado pelo container (seção 2).
- **`throttle:cotacoes`** = limite de requisições por minuto. Os limites são definidos em [AppServiceProvider.php:27](../app/Providers/AppServiceProvider.php#L27) (a chave do contador é o seller, ou o cliente da API quando não há seller).

⚠️ **Pegadinha que já nos custou um bug:** o Laravel passa os parâmetros da URL ao controller **pela ordem, não pelo nome**.
Com `{seller}` na frente, o método `show(Request $r, string $id)` receberia o id do seller no lugar do `{id}`. A solução está na seção 6.

---

## 4. `bootstrap/app.php`: middlewares e erros

Arquivo: [bootstrap/app.php](../bootstrap/app.php). É onde o Laravel 11+ configura a aplicação (no lugar do antigo `Kernel`).

Três blocos importantes:

**a) Rotas** (`withRouting`): diz que as rotas de API vivem em `routes/api.php` com prefixo `api/v1`, e registra `/up` (health check) na raiz.

**b) Middlewares** ([L31](../bootstrap/app.php#L31)):
```php
$middleware->alias([...]);                         // os apelidos usados em routes/api.php
$middleware->prepend([RequestId::class, ForcaJson::class]);   // rodam PRIMEIRO, em toda requisição
$middleware->append(CabecalhosDeSeguranca::class);            // rodam por ÚLTIMO (ajustam a resposta)
$middleware->throttleWithRedis();                  // contadores do limite ficam no Redis
$middleware->prependToPriorityList(...);           // garante a ORDEM: autenticação ANTES do throttle
```
Por que a ordem importa: o limite é **por seller**, então o seller precisa estar carregado antes de o `throttle` rodar.

**c) Erros** ([L50](../bootstrap/app.php#L50)): `$exceptions->render(new RenderizaExcecao())` faz **toda exceção** virar o JSON padrão (seção 13).

> **O que é um middleware?** Uma "camada" que a requisição atravessa antes de chegar no controller (e a resposta atravessa na volta).
> Ele recebe a requisição, pode **barrar** (lançando exceção), **alterar**, ou **passar adiante** com `return $next($request);`.

---

## 5. Passo 1: a chave de API (quem está chamando)

### O que é e o que não é
- Quem chama a API é o **B2C** (o "cliente da API", tabela `clientes_api`). Ele tem a chave.
- **Seller não tem chave.** O seller é só "em nome de quem".

### Formato da chave
`fk_3fa91c0b2d4e.<segredo>`:
- `fk_3fa91c0b2d4e` = **prefixo** (público): serve para achar a chave no banco rápido (coluna `prefixo` tem índice único).
- `<segredo>` = 32 bytes aleatórios. **O banco guarda só o hash (sha256) dele**, nunca o segredo. Nem nós conseguimos recuperar uma chave perdida.

### Criação: [ApiKeyService.php](../app/Services/ApiKeyService.php)
- [`gerar()`](../app/Services/ApiKeyService.php#L31): cria prefixo e segredo com `random_bytes` (aleatoriedade segura, não `rand()`).
- [`criarCliente()`](../app/Services/ApiKeyService.php#L61): cria o cliente e a primeira chave dentro de `DB::transaction` (ou grava tudo, ou nada).
- [`trocarChave()`](../app/Services/ApiKeyService.php#L93): cria uma chave nova e **mantém a antiga válida por 24h** (até 2 chaves ativas), para dar tempo de atualizar o sistema.

Quem chama isso são os comandos em [app/Console/Commands](../app/Console/Commands): `cliente:create`, `cliente:rotate-key`, `cliente:disable`, `cliente:list`.
(Comando artisan = uma classe com `$signature` e um `handle()`; o Laravel os descobre sozinho pela pasta.)

### Uso: o middleware [AutenticaCliente.php](../app/Http/Middleware/AutenticaCliente.php#L29)
Leia o `handle()`. Em português:

1. Monta a chave do contador `auth-falha:IP`. Se esse IP já errou muitas vezes, lança `ThrottleRequestsException` (vira 429).
2. `$this->chaves->autenticar($request->bearerToken())`: o `bearerToken()` lê o header `Authorization: Bearer ...`.
3. Se devolveu `null`, **registra a falha** para o IP e lança `AuthenticationException` (vira 401).
4. Se deu certo: guarda o cliente na requisição com `$request->attributes->set('cliente_api', $cliente)` e segue com `return $next($request)`.

O `$request->attributes` é uma "mochila" da requisição: dados que um middleware deixa para quem vem depois (controller, outros middlewares).

### O coração: [`ApiKeyService::autenticar`](../app/Services/ApiKeyService.php#L132)
```php
[$prefixo, $segredo] = self::separar($chaveCompleta);                       // quebra em prefixo e segredo
$chave = $prefixo === null ? null : ChaveApi::where('prefixo', $prefixo)->first();

// Calcula e compara o hash MESMO quando a chave não existe:
$esperado = $chave?->hash_segredo ?? str_repeat('0', 64);
$confere  = hash_equals($esperado, self::hash($segredo ?? ''));
```
- **`hash_equals`** compara em tempo constante. Um `===` comum para mais cedo no primeiro caractere diferente, e isso permite descobrir o segredo medindo o tempo de resposta.
- Calculamos o hash mesmo sem chave para que **"chave não existe" e "chave errada" demorem o mesmo tempo** (e respondam o mesmo 401): quem tenta não descobre se o prefixo existe.
- Depois checa revogada, expirada, cliente ativo. Qualquer falha devolve `null`, sem dizer o motivo.

### Modelos envolvidos
- [ClienteApi](../app/Models/ClienteApi.php): tem `chaves()`, `credenciais()` e `sellers()` (relações `hasMany`).
- [ChaveApi](../app/Models/ChaveApi.php): tem o escopo `ativas()`: `ChaveApi::ativas()` filtra não revogadas e não expiradas. (Um *scope* é um filtro reaproveitável da consulta.)

---

## 6. Passo 2: o seller da URL

Arquivo: [CarregaSeller.php](../app/Http/Middleware/CarregaSeller.php#L21). É o middleware `seller.da.url`.

```php
$id = (string) $request->route('seller');
$seller = Str::isUuid($id) ? $request->attributes->get('cliente_api')->sellers()->find($id) : null;
if ($seller === null) throw new ModelNotFoundException();   // vira 404
$request->route()->forgetParameter('seller');                // tira da lista de parâmetros
$request->attributes->set('seller', $seller);
```

Três ideias aqui:

1. **Isolamento entre sellers começa aqui.** Não buscamos `Seller::find($id)` (qualquer seller do banco). Buscamos `$cliente->sellers()->find($id)`: só sellers **do cliente que chamou**.
   Seller de outro cliente dá 404, igual a um que não existe: ninguém descobre se o id existe.
2. **`Str::isUuid($id)`**: o Postgres dá erro (500) se você buscar um UUID inválido. Validamos antes e viramos 404.
3. **`forgetParameter('seller')`**: a solução da pegadinha da seção 3. Como o seller já foi carregado, o tiramos da lista de parâmetros da rota; os controllers recebem só `{id}`, `{transportadora}`...

Nos controllers, [Controller.php](../app/Http/Controllers/Controller.php#L20) tem dois atalhos que leem a "mochila":
```php
$this->seller($request)    // o Seller carregado acima
$this->cliente($request)   // o ClienteApi autenticado
```

### Eloquent como barreira de segurança
Em todo o código, a consulta **parte do seller**:
```php
$seller->remessas()->findOrFail($id)      // só acha se a remessa for DESTE seller; senão, 404
```
`$seller->remessas()` é uma **relação** ([Seller.php:56](../app/Models/Seller.php#L56)): o Eloquent acrescenta `WHERE seller_id = ...` sozinho.
Esquecer o filtro é o erro clássico de vazamento entre clientes; por isso a regra é nunca começar de `Remessa::find(...)` nos controllers.

Um detalhe do [Seller.php:45](../app/Models/Seller.php#L45): `credenciais()` **não** é uma relação, é uma consulta (`Builder`) das credenciais **do cliente dono do seller**.
Isso porque as credenciais das transportadoras pertencem ao B2C, não ao seller.

---

## 7. Controller, Form Request, Service e Resource

O padrão se repete em toda rota. Vamos seguir **`POST /sellers/{seller}/cotacoes`** inteiro.

### 7.1 Form Request: validar antes de entrar no controller
[CotacaoRequest.php](../app/Http/Requests/CotacaoRequest.php) estende [ApiFormRequest](../app/Http/Requests/ApiFormRequest.php#L15). Quando ele aparece como parâmetro do controller, o Laravel:

1. cria o objeto;
2. roda [`prepareForValidation()`](../app/Http/Requests/CotacaoRequest.php#L10) (aqui: tira o hífen do CEP);
3. roda [`rules()`](../app/Http/Requests/CotacaoRequest.php#L19) (as regras: `'cep_destino' => ['required', 'string', 'regex:/^\d{8}$/']` etc.);
4. se falhar, lança `ValidationException` → **422** automático. **O controller nem é chamado.**

A classe-base [`ApiFormRequest::after()`](../app/Http/Requests/ApiFormRequest.php#L24) acrescenta uma regra para todos: **campo que não existe em `rules()` é recusado**
(digitou `valor_declarado_centavo` sem o "s"? Erro 422, em vez de passar em silêncio com valor vazio).

Depois do `validated()`, `$request->validated()` devolve **só** os campos validados. É por isso que nunca usamos `$request->all()`: campo estranho nunca entra.
[`CotacaoRequest::validated()`](../app/Http/Requests/CotacaoRequest.php#L37) ainda filtra os campos de cada volume.

### 7.2 Controller: fino
[CotacaoController::store](../app/Http/Controllers/CotacaoController.php#L15):
```php
$cotacao = $service->cotar($this->seller($request), $request->validated());
return (new CotacaoResource($cotacao))->response()->setStatusCode(201);
```
Duas linhas: chama o service e formata. Sem `if`, sem consulta ao banco.

### 7.3 Resource: o formato da resposta
[CotacaoResource.php](../app/Http/Resources/CotacaoResource.php) decide **quais campos** saem e com que nome. O model tem colunas que não devem vazar (por exemplo, `hash_requisicao` na remessa).
Separar o formato da resposta do model permite mudar o banco sem quebrar o contrato da API.

### 7.4 Service: onde mora a regra
O próximo capítulo detalha o [`CotacaoService`](../app/Services/CotacaoService.php#L28).

---

## 8. Cotação: falando com as transportadoras

### O desafio
Cotar na Jadlog e nos Correios **ao mesmo tempo** (se fosse um depois do outro, o tempo somaria), e **uma transportadora com problema não pode derrubar a cotação**.

### O contrato das transportadoras
[Transportadora.php](../app/Transportadoras/Transportadora.php) é uma **interface**: uma lista de métodos que toda transportadora precisa ter.
`Jadlog` e `Correios` a implementam (`class Jadlog implements Transportadora, PontosDeRetirada`).
O resto do sistema conversa só com a interface, nunca com a Jadlog diretamente.

A cotação usa dois métodos, em vez de um só `cotar()`. Por quê?
- [`pedidosDeCotacao($dados)`](../app/Transportadoras/Transportadora.php#L44): a transportadora **descreve** as chamadas HTTP que precisa (URL, headers, corpo). Não envia nada.
- [`lerCotacao($dados, $respostas)`](../app/Transportadoras/Transportadora.php#L53): recebe as respostas e devolve as opções (valor, prazo).

Dividido assim, **quem envia** é um só (o `ClienteHttp`), e envia **tudo de todas as transportadoras ao mesmo tempo**.

### O fluxo do [CotacaoService::cotar](../app/Services/CotacaoService.php#L28)
```
1. $seller->exigirAtivo()                      → seller desabilitado? erro 409
2. credenciais ativas do B2C                   → nenhuma? erro 422 'sem_transportadoras'
3. para cada credencial:
      $t = Transportadoras::criar($credencial)   → cria Jadlog ou Correios
      $t->pedidosDeCotacao($dados)               → "o que eu preciso chamar?"
4. $this->http->enviarEmParalelo(...)          → manda TODAS as chamadas juntas
5. para cada transportadora:
      $t->lerCotacao($dados, $respostasDela)     → opções; se der problema, vai para "falhas"
6. ordena por valor e salva a Cotacao (resultados em jsonb, expira em 15 min)
```
O `try/catch` em volta de cada transportadora é o que garante: **falhou a Jadlog? A cotação sai só com os Correios**, e a falha aparece em `falhas`.

### Quem cria a transportadora certa: [Transportadoras.php](../app/Transportadoras/Transportadoras.php)
- `LISTA` mapeia o código (`'jadlog'`) para a classe. **É o único lugar a editar para registrar uma transportadora nova.**
- `criar()` lê `$credencial->transportadora` e cria a classe certa (seção 2, "onde não usamos DI").

### As chamadas HTTP: [ClienteHttp.php](../app/Transportadoras/ClienteHttp.php)
Todo HTTP para transportadora passa por aqui. Ele garante, para todas:
- **timeout** curto (3 s), sem seguir redirecionamentos, TLS verificado;
- **histórico** em `chamadas_transportadoras`, com dados sensíveis **mascarados** ([Mascaramento.php](../app/Support/Mascaramento.php): tira token, CPF, e-mail etc.).

[`enviarEmParalelo`](../app/Transportadoras/ClienteHttp.php#L52) usa `Http::pool(...)` do Laravel, que dispara várias requisições ao mesmo tempo e espera todas.
Uma requisição que dá timeout volta como `null` (em vez de explodir tudo).

### Uma transportadora por dentro: [Jadlog.php](../app/Transportadoras/Jadlog.php)
Leia nesta ordem:
1. [`regras()`](../app/Transportadoras/Jadlog.php#L19) e [`perguntas()`](../app/Transportadoras/Jadlog.php#L41): que dados o cadastro dela exige (seção 9).
2. [Construtor](../app/Transportadoras/Jadlog.php#L71): recebe `$credencial` (token etc.), `ClienteHttp` e o `$sellerId` (só para o histórico).
3. [`pedidosDeCotacao`](../app/Transportadoras/Jadlog.php#L84): monta o JSON do PDF da Jadlog. Calcula o peso (maior entre real e cubado: [`pesoEmKg`](../app/Transportadoras/Jadlog.php#L438)) e divide em chamadas de no máximo 3 modalidades.
4. [`lerCotacao`](../app/Transportadoras/Jadlog.php#L121): converte `vltotal` (reais) em **centavos** com [`Dinheiro::deReais`](../app/Support/Dinheiro.php) e identifica os erros.

**Por que centavos?** `0.1 + 0.2` em ponto flutuante dá `0.30000000000000004`. Dinheiro em `float` acumula erro. Em inteiro de centavos, `10 + 20 = 30`, sempre.

### Erros das transportadoras: [TransportadoraIndisponivel.php](../app/Transportadoras/TransportadoraIndisponivel.php)
Uma exceção com um `$motivo` curto e seguro (`sem_resposta`, `credencial_invalida`, `erro_na_transportadora`, `resposta_invalida`, `sem_cobertura`).
O método estático `motivoDaResposta()` olha uma resposta HTTP e diz se ela indica problema da transportadora (401/403, 5xx, nulo) ou se pode ser lida normalmente.
O seller **só vê esse motivo**; o texto original da transportadora fica só no histórico de chamadas.

### Os Correios: [Correios.php](../app/Transportadoras/Correios.php)
Diferenças que justificam o desenho em dois passos:
- Preço e prazo são **duas APIs separadas** (então dois tipos de chamada no mesmo `pedidosDeCotacao`).
- O preço é por **objeto**: um pedido com 2 caixas = 2 consultas de preço somadas.
- Precisa de um **token** que dura ~24h: [`token()`](../app/Transportadoras/Correios.php#L462) guarda no cache (Redis) e só pede outro quando vence.
  O cache é por credencial do B2C, então vale para todos os sellers.

---

## 9. Cadastro de sellers e das credenciais

### Sellers (por API)
- [SellerController](../app/Http/Controllers/SellerController.php) → [SellerService](../app/Services/SellerService.php#L22): `criar()` usa `$cliente->sellers()->create(...)` (o seller já nasce ligado ao cliente).
- `codigo_externo` repetido: [`criar`](../app/Services/SellerService.php#L22) lança [`ErroDeNegocio`](../app/Exceptions/ErroDeNegocio.php) com 409 e o id do que já existe.
  Há **dois cuidados**: uma checagem antes, e um `catch (UniqueConstraintViolationException)` para o caso de duas chamadas simultâneas passarem pela checagem. Quem decide de verdade é a **constraint única do banco**.

### `ErroDeNegocio`: erros "previstos"
```php
throw new ErroDeNegocio('cotacao_expirada', 'A cotacao expirou. Faca uma nova.', 422);
```
Um código (para máquinas), uma mensagem (para pessoas) e o status HTTP. Qualquer service pode lançar; a seção 13 mostra como vira resposta.

### Credenciais das transportadoras (só por comando)
[ConfigurarTransportadora.php](../app/Console/Commands/ConfigurarTransportadora.php#L24) (`transportadora:configurar`):
1. acha a classe da transportadora em `Transportadoras::LISTA`;
2. percorre `Classe::perguntas()` e faz cada pergunta (`secret()` esconde o que você digita);
3. valida tudo com `Classe::regras()` (o mesmo estilo de regras dos Form Requests, usando o `Validator` direto);
4. salva por [`SellerService::configurarTransportadora`](../app/Services/SellerService.php#L56).

No model [CredencialTransportadora](../app/Models/CredencialTransportadora.php) os segredos usam `'credenciais' => 'encrypted:array'`:
o Laravel **criptografa ao gravar e descriptografa ao ler**, com a `APP_KEY`. No banco aparece só texto ilegível.
E `$hidden = ['credenciais']` garante que nem um `toArray()` distraído os exponha.

---

## 10. Remessa: criar sem duplicar (idempotência)

### O problema
O B2C chama `POST /remessas`, a rede cai antes da resposta chegar, e ele **repete a chamada**. Sem cuidado, criaríamos **duas remessas** (e pagaríamos dois fretes).

### A solução: `Idempotency-Key`
O B2C manda um header com uma chave única por remessa (o id do pedido serve). Regra:
- **mesma chave + mesmo pedido** → devolve a remessa que já existe (200), sem criar outra;
- **mesma chave + pedido diferente** → erro 422.

### Os arquivos
1. [RemessaRequest.php:14](../app/Http/Requests/RemessaRequest.php#L14): em `prepareForValidation`, o header vira um campo (`$this->merge(['idempotency_key' => $this->header('Idempotency-Key')])`) para ser validado como qualquer outro.
2. [RemessaController::store](../app/Http/Controllers/RemessaController.php#L17): chama o service e responde 201 (criou) ou 200 (repetição), com o header `Idempotent-Replayed`.
3. O trabalho: [`RemessaService::criarEnvio`](../app/Services/RemessaService.php#L38).

### `criarEnvio` passo a passo
```
 1. hash = hashDoPedido(...)                 resumo (sha256) do pedido, igual para pedidos iguais
 2. já existe remessa com essa chave?        → reaproveitar(): mesmo hash = devolve; hash diferente = 422
 3. seller ativo?                            → senão 409
 4. cotação existe, não expirou, e a opção   → erros claros (404/422) se algo estiver errado
    escolhida (transportadora + serviço)
    está nela?
 5. CEPs da remessa = CEPs da cotação?       → senão 422 (o preço foi calculado para outros CEPs)
 6. reservar()                               → grava a remessa com status "criando"
 7. criarNaTransportadora()                  → chama a Jadlog/Correios de verdade
 8. atualiza para "criada" + códigos         → guarda etiqueta no Storage, se vier
```

### Por que "reservar" antes de chamar a transportadora (passo 6)?
Se chamássemos a transportadora primeiro e só depois gravássemos, duas requisições simultâneas com a mesma chave **criariam dois pedidos** na transportadora antes de qualquer uma gravar no banco.
Gravando **primeiro** (status `criando`), a constraint única `(seller_id, idempotency_key)` do banco garante que **só uma passa**. A outra recebe o erro do banco.

Veja [`reservar`](../app/Services/RemessaService.php#L261):
```php
try {
    return DB::transaction(fn () => $seller->remessas()->create(...));
} catch (UniqueConstraintViolationException) {
    return null;     // outra requisição chegou primeiro
}
```
E o `criarEnvio` trata `null` como "reaproveitar a que já existe".

> **Por que `DB::transaction` ao redor de uma única inserção?** No Postgres, depois de um erro, a transação em andamento fica "quebrada" e recusa comandos. Quando já estamos dentro de uma transação (nos testes, por exemplo), o Laravel transforma a interna num *savepoint*, e o erro desfaz só aquele trecho.

### Se a transportadora falhar (passo 7)
[`criarNaTransportadora`](../app/Services/RemessaService.php#L275) captura a exceção, **apaga a reserva** (`$remessa->delete()`) e relança.
Assim a chave fica livre e o B2C pode tentar de novo com a mesma `Idempotency-Key`.
Se a falha for `TransportadoraIndisponivel`, ela é convertida em `ErroDeNegocio` 502 por [`paraErroDeNegocio()`](../app/Transportadoras/TransportadoraIndisponivel.php).

⚠️ **Risco conhecido:** se a transportadora criar o pedido mas **não responder a tempo**, nós apagamos nossa reserva e uma nova tentativa pode duplicar lá. Está nas dúvidas em aberto (a Jadlog permite enviarmos nosso próprio `shipmentId`).

### O enum de status: [StatusRemessa.php](../app/Enums/StatusRemessa.php)
`criando → criada → despachada → em_transito → saiu_para_entrega → entregue` (ou `cancelada`).
Em vez de `'criada'` espalhado como texto, usamos um **enum**: `StatusRemessa::Criada`. Erros de digitação viram erro de código, e as regras ficam junto do tipo:
`$status->podeCancelar()`, `$status->podeDespachar()`.
O model faz a conversão sozinho ([Remessa.php:28](../app/Models/Remessa.php#L28): `'status' => StatusRemessa::class` no `casts()`).

### Dados pessoais criptografados
No mesmo `casts()`: `'destinatario' => 'encrypted:array'`. O endereço e o CPF do destinatário ficam ilegíveis no banco (LGPD).

---

## 11. Despachar, cancelar e etiqueta

Mesmo padrão: controller fino → service.

- **Despachar** ([`RemessaService::despachar`](../app/Services/RemessaService.php#L178)): só muda o status (`criada → despachada`), **sem chamar a transportadora**.
  Valida com o enum: `if (! $remessa->status->podeDespachar()) throw new ErroDeNegocio(...)` (409).
- **Cancelar** ([`cancelar`](../app/Services/RemessaService.php#L191)): valida o status, **chama a transportadora**, e só então muda o status. Se ela recusar, a remessa fica como estava.
  Cancelar algo já cancelado na transportadora **não é erro** (o resultado que queremos já existe).
- **Etiqueta** ([`etiqueta`](../app/Services/RemessaService.php#L213)): se já temos o PDF no disco, devolve; se não, pede à transportadora e guarda.
  O PDF **nunca vai para o banco**: usamos `Storage::disk(...)` (disco configurável em `config/filesystems.php`; trocar por S3 é só mudar uma variável).

Cada transportadora implementa isso à sua maneira (`criarRemessa`, `cancelarRemessa`, `etiqueta` na interface):
- **Jadlog**: o PDF vem junto da criação; cancelar usa o `shipmentId`.
- **Correios**: cada volume vira uma pré-postagem; a etiqueta é gerada depois, de forma assíncrona.

---

## 12. Devolução

[`criarDevolucao`](../app/Services/RemessaService.php#L113) usa a **mesma estrutura** (e a mesma tabela `remessas`, com `tipo = 'devolucao'`). As diferenças:
- só vale para remessa **entregue**;
- **inverte** remetente e destinatário: `'remetente' => $origem->destinatario`, `'destinatario' => $origem->remetente`;
- usa a mesma transportadora e serviço da remessa original;
- quem paga o frete (tomador, na Jadlog) continua sendo o titular do contrato (o B2C).

Reaproveitar o mesmo mecanismo (`reservar`, `criarNaTransportadora`) é intencional: a devolução ganhou idempotência e tratamento de falhas de graça.

---

## 13. Como os erros chegam ao cliente

Em qualquer ponto você pode fazer `throw`. Quem transforma isso em resposta é o [RenderizaExcecao](../app/Exceptions/RenderizaExcecao.php#L27), registrado em [bootstrap/app.php](../bootstrap/app.php#L50).
Ele olha o **tipo** da exceção:

| Exceção | Resposta |
|---|---|
| `ErroDeNegocio` | o status e o código que você definiu |
| `ValidationException` (Form Request) | 422 com os campos errados |
| `AuthenticationException` | 401 |
| `ModelNotFoundException` (`findOrFail`) | 404 |
| `ThrottleRequestsException` | 429 + `Retry-After` |
| qualquer outra | **500 genérico**, sem stack trace, SQL ou detalhes |

O formato é sempre `{"erro": {"codigo", "mensagem", "detalhes?"}, "request_id": "..."}`.
O `request_id` vem do [RequestId.php](../app/Http/Middleware/RequestId.php#L22) (primeiro middleware). Ele também entra em **todo log** via `Context::add(...)`, e é assim que achamos no log o que o cliente viu.

Além disso, para `QueryException` (erros de SQL), o log registra só o código do erro: a mensagem original traria o SQL com **os valores** (dados pessoais).

---

## 14. O simulador (modo mock)

Sem conta real nas transportadoras, como testar? Com `TRANSPORTADORAS_MODO=mock`, o [AppServiceProvider](../app/Providers/AppServiceProvider.php#L38) chama [`SimuladorHttp::instalar()`](../app/Transportadoras/Mock/SimuladorHttp.php#L33), que usa o `Http::fake(...)` do Laravel:

```php
Http::fake(fn (Request $requisicao) => self::responder($requisicao));
```

A partir daí, **toda chamada HTTP do `Http::` é interceptada**: o código da Jadlog e dos Correios roda de verdade, mas a resposta vem de arquivos em [Mock/fixtures](../app/Transportadoras/Mock/fixtures) (cópias dos exemplos dos PDFs).
O CEP de destino escolhe o cenário (timeout, erro 500...). A tabela está em [Mock/README.md](../app/Transportadoras/Mock/README.md).

Retornar `null` no callback significa "não simulo esta": a chamada segue para a internet de verdade. É assim que o ViaCEP **não** é simulado em desenvolvimento.

Por segurança, o provider **recusa subir** o modo mock com `APP_ENV=production`.

---

## 15. Como os testes funcionam

Pasta [tests/](../tests). Usamos Pest. Rode com `docker/scripts/test.sh`.

- [Pest.php](../tests/Pest.php): todo teste de `Feature` usa o app inteiro e **desfaz as alterações do banco** ao fim (`DatabaseTransactions`).
- [TestCase.php](../tests/TestCase.php): helpers para montar cenários (`criarSeller()`, `configurarJadlog()`, `criarCotacaoComOpcoes()`, `comChave()`...). Eles criam dados já no formato correto, para o teste focar no que importa.
- Os testes chamam a API de verdade (`$this->postJson(url, dados, headers)`) e conferem status e corpo. Eles usam o simulador.
- Tipos: **Unit** (sem banco: `Mascaramento`, `Dinheiro`, formato de chave) e **Feature** (fluxos completos).
- Há testes "de regra do projeto", como o que garante que **nenhum código fora de `app/Transportadoras/` cita Jadlog ou Correios** pelo nome.

Dica para ler um teste: o nome em português diz a regra (`it('repetir a chamada com a mesma chave devolve a mesma remessa...')`). Comece lendo só os nomes.

---

## 16. Exercício: siga um `POST /remessas`

Abra os arquivos e marque cada parada. Chamada: `POST /api/v1/sellers/{seller}/remessas`, com o header `Idempotency-Key`.

1. [`bootstrap/app.php`](../bootstrap/app.php): `RequestId`, `ForcaJson` rodam primeiro (global).
2. [`routes/api.php:41`](../routes/api.php#L41): a rota casa; os grupos aplicam `auth.cliente`, `seller.da.url` e `throttle:remessas`.
3. [`AutenticaCliente`](../app/Http/Middleware/AutenticaCliente.php#L29): valida a chave → `cliente_api` na mochila.
4. [`CarregaSeller`](../app/Http/Middleware/CarregaSeller.php#L21): acha o seller do cliente, tira `{seller}` dos parâmetros.
5. Throttle: conta a requisição para este seller (Redis).
6. [`RemessaRequest`](../app/Http/Requests/RemessaRequest.php#L14): normaliza e valida (CPF/CNPJ, UF, tamanhos); campo desconhecido = 422.
7. [`RemessaController::store`](../app/Http/Controllers/RemessaController.php#L17): chama o service.
8. [`RemessaService::criarEnvio`](../app/Services/RemessaService.php#L38): idempotência → validações → `reservar` → `criarNaTransportadora`.
9. [`Transportadoras::criar`](../app/Transportadoras/Transportadoras.php#L27) → `Jadlog::criarRemessa` ([L233](../app/Transportadoras/Jadlog.php#L233)) → [`ClienteHttp::enviar`](../app/Transportadoras/ClienteHttp.php#L28) (HTTP + histórico mascarado).
10. Volta ao service: atualiza para `criada`, guarda a etiqueta.
11. [`RemessaResource`](../app/Http/Resources/RemessaResource.php): monta o JSON; o controller responde 201.
12. [`CabecalhosDeSeguranca`](../app/Http/Middleware/CabecalhosDeSeguranca.php) acrescenta os headers; [`RequestId`](../app/Http/Middleware/RequestId.php) acrescenta `X-Request-Id`.

Agora tente responder (a resposta está no capítulo indicado):
- O que acontece se a Jadlog der timeout no passo 9? *(cap. 10)*
- Por que repetir a chamada não cria uma segunda remessa? *(cap. 10)*
- Por que o seller B não consegue ver a remessa do seller A, mesmo com a mesma chave de API? *(cap. 6)*
- Onde eu mexeria para adicionar uma terceira transportadora? *(cap. 8: criar a classe + `Transportadoras::LISTA`)*

---

## Vocabulário rápido

| Termo | Em uma frase |
|---|---|
| **Container de serviços** | O "fabricante" de objetos do Laravel: cria classes olhando os tipos do construtor |
| **Injeção de dependência** | Receber o que você precisa pelo construtor/parâmetro, em vez de criar por dentro |
| **Middleware** | Camada que a requisição atravessa antes do controller |
| **Form Request** | Classe que valida o corpo antes de o controller rodar |
| **Resource** | Classe que define o formato JSON de uma resposta |
| **Service** | Classe com a regra de negócio (nosso padrão; o Laravel não exige) |
| **Eloquent / relação** | Os models; `$seller->remessas()` gera a consulta já filtrada pelo seller |
| **`casts()`** | Conversão automática de coluna: `encrypted:array`, enum, datetime |
| **Constraint única** | Regra do banco que impede duplicata; é a defesa final contra corrida de requisições |
| **Idempotência** | Repetir a mesma operação não muda o resultado além da primeira vez |
| **Enum** | Tipo com valores fixos (`StatusRemessa::Criada`), em vez de texto solto |
| **Mock / `Http::fake`** | Resposta falsa para uma chamada HTTP, para não depender de serviço externo |
