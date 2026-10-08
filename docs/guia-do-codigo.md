# Guia do código: da autenticação até as devoluções

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
5. [Passo 1: quem está chamando (as três portas)](#5-passo-1-quem-está-chamando-as-três-portas)
6. [Passo 2: tudo parte do cliente autenticado](#6-passo-2-tudo-parte-do-cliente-logado)
7. [Controller, Form Request, Service e Resource](#7-controller-form-request-service-e-resource)
8. [Cotação: falando com as transportadoras](#8-cotação-falando-com-as-transportadoras)
9. [Cadastro de clientes e das credenciais](#9-cadastro-de-clientes-e-das-credenciais)
10. [Remessa: criar sem duplicar (idempotência)](#10-remessa-criar-sem-duplicar-idempotência)
11. [Despachar, cancelar e etiqueta](#11-despachar-cancelar-e-etiqueta)
12. [Devolução](#12-devolução)
13. [Como os erros chegam ao cliente](#13-como-os-erros-chegam-ao-cliente)
14. [O simulador (modo mock)](#14-o-simulador-modo-mock)
15. [Como os testes funcionam](#15-como-os-testes-funcionam)
16. [Exercício: siga um `POST /remessas`](#16-exercício-siga-um-post-remessas)

---

## 1. O caminho de uma requisição

Tudo o que acontece quando o backend de um cliente chama `POST /api/v1/cotacoes` (com a chave de API):

```
  Cliente ──HTTP──► nginx ──► php-fpm ──► public/index.php ──► bootstrap/app.php
                                                                 │
        middlewares GLOBAIS (valem para toda rota)               ▼
        RequestId → ForcaJson → ... → CabecalhosDeSeguranca
                                                                 │
        routes/api.php escolhe a rota e seus middlewares         ▼
        AutenticaCliente (chave de API) → throttle (limite)
        (no portal seria: StartSession → AutenticaPortal → [ExigePapel] → throttle)
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
$cotacao = $service->cotar($cliente, $dados);
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
| Middleware | [AutenticaCliente.php:26](../app/Http/Middleware/AutenticaCliente.php#L26): o construtor recebe `ChaveApiService` |
| Controller | parâmetros do método: `CotacaoService $service`, `RemessaService $service`... |
| Service | construtor: `CotacaoService` recebe `ClienteHttp`; `ConsultasService` recebe `ViaCep` |
| Form Request | declarado como parâmetro do método do controller: o Laravel o cria **e roda as validações antes** de chamar o método |
| Comando artisan | parâmetro de `handle()`: `handle(TransportadoraService $service)` (veja o [`ConfigurarTransportadora`](../app/Console/Commands/ConfigurarTransportadora.php)) |

### Onde NÃO usamos DI (e por quê)
Em [Transportadoras.php:27](../app/Transportadoras/Transportadoras.php#L27) criamos a classe da transportadora "na mão":

```php
$classe = self::LISTA[$credencial->transportadora];          // 'jadlog' → Jadlog::class
return new $classe($credencial, app(ClienteHttp::class), $clienteId);
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

Arquivo: [routes/api.php](../routes/api.php). O comentário no topo dele resume as **três portas de entrada**. Leia de cima para baixo:

```php
// 1. Administração: só nós, com o token de admin
Route::prefix('admin')->middleware(['auth.admin', 'throttle:cadastros'])->group(function () {
    Route::post('clientes', [ClienteController::class, 'store']);
    ...
});

// As rotas de negócio são definidas UMA vez, dentro de funções:
$rotasDoDiaADia = function () {
    Route::post('cotacoes', [CotacaoController::class, 'store'])->middleware('throttle:cotacoes');
    Route::get('remessas', [RemessaController::class, 'index']);
    Route::get('remessas/{id}', ...)->whereUuid('id');
    ...
};
$rotasDeConfiguracao = function () {          // PUT/PATCH /transportadoras/{t}
    ...
};

// 2. Chave de API: o backend do cliente
Route::middleware('auth.cliente')->group(function () use (...) {
    $rotasDoDiaADia();
    $rotasDeConfiguracao();
});

// 3. Portal: pessoas do cliente (cookie de sessão)
Route::prefix('portal')->middleware('portal')->group(function () use (...) {
    Route::post('login', [SessaoController::class, 'login'])->middleware('throttle:login');   // sem login ainda
    Route::middleware('auth.portal')->group(function () use (...) {
        Route::post('logout', ...); Route::get('eu', ...);
        $rotasDoDiaADia();                                        // dono e operador
        Route::middleware('papel:adm_tenant')->group(function () use (...) {   // só o dono
            $rotasDeConfiguracao();
            Route::post('chaves', ...); Route::post('usuarios', ...); ...
        });
    });
});
```

A ideia central: `$rotasDoDiaADia()` é chamada **duas vezes**, uma sob `auth.cliente` e outra sob `auth.portal`. As rotas (e os controllers) são as mesmas;
muda só **quem as protege**. Uma rota de negócio nova entra em uma função só e já vale para as duas portas.

Pontos para entender:

- **`Route::middleware(...)->group(...)`**: aplica o middleware a todas as rotas de dentro. Qualquer rota nova que você colocar aí já exige autenticação.
- **`'auth.cliente'`, `'auth.admin'`, `'auth.portal'` e `'papel'` são apelidos** (alias) de classe (`AutenticaCliente`, `AutenticaAdmin`, `AutenticaPortal`, `ExigePapel`). Quem define os apelidos é o [bootstrap/app.php](../bootstrap/app.php).
- **`'portal'` é um grupo de middlewares** (cookies criptografados + sessão), definido em [bootstrap/app.php](../bootstrap/app.php). **Só o portal tem cookie e sessão**: a API de chave de API não usa cookie.
- **`papel:adm_tenant`** passa o parâmetro `adm_tenant` para o `ExigePapel`: só o dono passa; operador recebe 403.
- **`/api/v1`** não aparece no arquivo: é adicionado automaticamente em [bootstrap/app.php](../bootstrap/app.php) (`apiPrefix: 'api/v1'`).
- **Não há cliente na URL.** Quem está chamando vem da autenticação, não de `/clientes/{id}/...`. Assim ninguém consegue "trocar o id na URL" para ver dados de outro cliente.
- **`{id}` e `{transportadora}`** são parâmetros da URL. `->whereUuid('id')` só deixa a rota casar se for um UUID: `/remessas/abc` dá 404 sem sequer chegar no controller.
  `->whereIn('transportadora', Transportadoras::codigos())` faz o mesmo para `jadlog` e `correios`.
- **`[CotacaoController::class, 'store']`** = "chame o método `store` desta classe". Por isso o controller é criado pelo container (seção 2).
- **`throttle:cotacoes`** = limite de requisições por minuto. Os limites são definidos em [AppServiceProvider.php](../app/Providers/AppServiceProvider.php) (a chave do contador é o id do cliente, ou o IP se a requisição ainda não tem cliente; o `throttle:login` usa e-mail + IP).

---

## 4. `bootstrap/app.php`: middlewares e erros

Arquivo: [bootstrap/app.php](../bootstrap/app.php). É onde o Laravel 11+ configura a aplicação (no lugar do antigo `Kernel`).

Três blocos importantes:

**a) Rotas** (`withRouting`): diz que as rotas de API vivem em `routes/api.php` com prefixo `api/v1`, e registra `/up` (health check) na raiz.

**b) Middlewares** ([`withMiddleware`](../bootstrap/app.php#L36)):
```php
$middleware->alias([...]);                         // os apelidos usados em routes/api.php
$middleware->prepend([RequestId::class, ForcaJson::class]);   // rodam PRIMEIRO, em toda requisição
$middleware->append(CabecalhosDeSeguranca::class);            // rodam por ÚLTIMO (ajustam a resposta)
$middleware->throttleWithRedis();                  // contadores do limite ficam no Redis
$middleware->prependToPriorityList(...);           // garante a ORDEM: autenticação ANTES do throttle
```
Por que a ordem importa: o limite é **por cliente**, então o cliente precisa estar identificado antes de o `throttle` rodar. (Por isso `AutenticaCliente`, `AutenticaAdmin` e `AutenticaPortal` entram na lista de prioridade antes do `ThrottleRequests`.)

O grupo `portal` (`$middleware->group('portal', [EncryptCookies, AddQueuedCookiesToResponse, StartSession])`) liga cookie e sessão **só** nas rotas do portal.

**c) Erros** ([`withExceptions`](../bootstrap/app.php#L64)): `$exceptions->render(new RenderizaExcecao())` faz **toda exceção** virar o JSON padrão (seção 13).

> **O que é um middleware?** Uma "camada" que a requisição atravessa antes de chegar no controller (e a resposta atravessa na volta).
> Ele recebe a requisição, pode **barrar** (lançando exceção), **alterar**, ou **passar adiante** com `return $next($request);`.

---

## 5. Passo 1: quem está chamando (as três portas)

Antes de qualquer regra de negócio, o hub precisa saber **quem** está chamando. Há três portas, cada uma com um middleware:

| Porta | Middleware | Como prova quem é | O que deixa na "mochila" (`$request->attributes`) |
|---|---|---|---|
| Administração | [AutenticaAdmin](../app/Http/Middleware/AutenticaAdmin.php) | `Bearer <ADMIN_API_TOKEN>` | nada (só libera) |
| Chave de API | [AutenticaCliente](../app/Http/Middleware/AutenticaCliente.php) | `Bearer fs_<prefixo>.<segredo>` | `cliente` |
| Portal | [AutenticaPortal](../app/Http/Middleware/AutenticaPortal.php) | cookie de sessão | `usuario` e `cliente` |

O **cliente** (tabela `clientes`, model [Cliente](../app/Models/Cliente.php)) é o **tenant**: contrato com as transportadoras, cotações e remessas são dele.
Ele tem `id`, `nome`, `cnpj` (único) e `ativo`. Quem **usa** o hub são a **chave de API** (máquina) e os **usuários** do portal (pessoas).

### 5.1 Chave de API: [ChaveApiService](../app/Services/ChaveApiService.php)

Formato: `fs_<prefixo>.<segredo>`.

- O **prefixo** (`fs_` + 12 caracteres hexadecimais) é público e **único** na tabela `chaves_api`: serve para achar a linha da chave (e, por ela, o `cliente_id`).
- O **segredo** são 32 bytes aleatórios (`random_bytes`) em base64 url-safe. **O banco guarda só o sha256** dele (coluna `hash_segredo`).
- Um cliente tem **várias chaves** (`producao`, `homologacao`...). Cada uma pode ser revogada sozinha (`revogada_em`).

Métodos:
- `criar($cliente, $nome)`: gera prefixo e segredo, grava o hash e devolve `[ChaveApi, 'texto completo']`. **Essa é a única vez que o texto existe.**
- `revogar($chave)`: preenche `revogada_em`. A chave passa a dar 401 na hora.
- `autenticar($textoDaChave)`: o coração. Veja abaixo.

```php
[$prefixo, $segredo] = $this->separar($chaveCompleta);                 // "prefixo.segredo"; formato errado = [null, null]
$chave = $prefixo === null ? null : ChaveApi::where('prefixo', $prefixo)->first();

// Compara o hash MESMO quando a chave não existe, para o tempo de resposta ser o mesmo.
$esperado = $chave?->hash_segredo ?? str_repeat('0', 64);
$confere  = hash_equals($esperado, hash('sha256', $segredo ?? ''));

if ($chave === null || ! $confere || $chave->revogada_em !== null) return null;
// ...e o cliente dono da chave precisa existir e estar ativo
```

- **`hash_equals`** compara em tempo constante. Um `===` comum para no primeiro caractere diferente, e isso permite descobrir o segredo medindo o tempo de resposta.
- Calculamos o hash mesmo sem chave para que **"chave não existe" e "segredo errado" demorem o mesmo tempo** (e deem o mesmo 401).
- Por que **sha256 e não bcrypt**? O segredo já é aleatório e longo (256 bits), então não dá para adivinhar nem por dicionário; sha256 é rápido, e isso importa porque **toda chamada** da API confere a chave.
  Já a **senha de uma pessoa** é curta e previsível, por isso usa bcrypt (lento de propósito).
- O `ultimo_uso_em` é atualizado no máximo a cada 5 minutos (para não gravar no banco a cada chamada).

O [AutenticaCliente](../app/Http/Middleware/AutenticaCliente.php) (`handle()`), em português:

1. Monta o contador `auth-falha:IP`. Se esse IP já errou muitas vezes, lança `ThrottleRequestsException` (vira 429).
2. `$this->chaves->autenticar($request->bearerToken())`: o Laravel já extrai o texto depois de `Bearer `.
3. Se devolveu `null`, **registra a falha** para o IP e lança `AuthenticationException` (vira 401, com o header `WWW-Authenticate: Bearer`).
4. Se deu certo: `$request->attributes->set('cliente', $cliente)` e `return $next($request)`.

O `$request->attributes` é uma "mochila" da requisição: dados que um middleware deixa para quem vem depois (controller, outros middlewares).

**Quem cria as chaves:** o **dono** do cliente, no portal ([Portal\ChaveApiController](../app/Http/Controllers/Portal/ChaveApiController.php): `GET/POST /portal/chaves`, `DELETE /portal/chaves/{id}`).
O `store` devolve o texto completo uma única vez; o `index` mostra só `prefixo`, `ultimo_uso_em` e `revogada_em`.

### 5.2 Portal: login com e-mail e senha e cookie de sessão

**Usuário** ([Usuario](../app/Models/Usuario.php)): `nome`, `email` (único no sistema, em minúsculas), `password` (hash bcrypt: o cast `'hashed'` faz isso sozinho ao gravar),
`papel` (enum [PapelUsuario](../app/Enums/PapelUsuario.php)) e `ativo`. A senha está em `$hidden`: nem um `toJson()` distraído a expõe.

**Papéis:**
- `adm_tenant` (dono): criado **junto com o cliente** ([ClienteService::criar](../app/Services/ClienteService.php), numa transação). Faz tudo.
- `operador_tenant`: criado pelo dono ([UsuarioService::criarOperador](../app/Services/UsuarioService.php)). Só o dia a dia.

**Login** ([Portal\SessaoController::login](../app/Http/Controllers/Portal/SessaoController.php)):
1. Procura o usuário pelo e-mail e confere a senha com `Hash::check`. Se o e-mail não existe, confere contra um **hash falso** (`HASH_FALSO`), para o tempo ser igual.
2. E-mail inexistente, senha errada, usuário bloqueado ou cliente desabilitado: **o mesmo 401**.
3. `Auth::guard('portal')->login($usuario)` e `session()->regenerate()`: a sessão é **nova a cada login** (evita "session fixation": alguém plantar um id de sessão antes do login).
4. O limite `throttle:login` (5/min por e-mail + IP) protege contra adivinhar senha.

**O cookie** (configurado em [config/session.php](../config/session.php)): `HttpOnly` (o JavaScript não lê), `SameSite=Strict` (só vai em chamadas do mesmo site) e `Secure` em produção.
A sessão em si fica no **Redis** (`SESSION_DRIVER=redis`); o cookie só carrega o número dela. Com `SESSION_DRIVER=array` o login não persiste.

**Em cada chamada**, o [AutenticaPortal](../app/Http/Middleware/AutenticaPortal.php) lê o usuário do guard `portal` e **confere de novo** `usuario->ativo` e `cliente->ativo`:
quem foi bloqueado perde o acesso **na hora**, mesmo com a sessão aberta. Depois deixa `usuario` e `cliente` na mochila. Para os controllers de negócio isso é indiferente: eles só chamam `$this->cliente($request)`.

**Papel por rota:** [ExigePapel](../app/Http/Middleware/ExigePapel.php) (`->middleware('papel:adm_tenant')`) compara o papel do usuário com o exigido; diferente = `AuthorizationException` = **403 `proibido`**.

Outros arquivos: [Portal\UsuarioController](../app/Http/Controllers/Portal/UsuarioController.php) (o dono lista, cria e atualiza operadores; só encontra operadores **do próprio cliente**, o resto dá 404) e
[UsuarioService](../app/Services/UsuarioService.php). Trocar a própria senha: `SessaoController::trocarSenha`.

### 5.3 Administração: [AutenticaAdmin](../app/Http/Middleware/AutenticaAdmin.php)

As rotas `/admin` usam `auth.admin`. Ele exige `Authorization: Bearer <ADMIN_API_TOKEN>`:
- O token vem de `config('frete.admin_token')` (variável `ADMIN_API_TOKEN`). **Vazio ou com menos de 32 caracteres = administração desligada**: tudo dá 401.
- Compara com `hash_equals` (tempo constante).
- Falha = 401 sempre igual; falhas seguidas do mesmo IP dão 429 (mesmo contador da chave de API).
- O token de admin não vale como chave de API, e a chave de API não entra no `/admin`.

[Admin\ClienteController](../app/Http/Controllers/Admin/ClienteController.php): `store` cria cliente **e dono** ([ClienteRequest](../app/Http/Requests/ClienteRequest.php) valida CNPJ e dono) e responde 201 com cliente + dono;
`index`, `show` e `update` (`nome`, `cnpj`, `ativo`). Desligar o cliente (`ativo = false`) bloqueia chaves e portal de uma vez (veja as checagens de `ativo` acima).

### 5.4 Por que três mecanismos e não um só?

Cada um serve a um tipo de usuário: **máquina** (chave de API, sem cookie, revogável, várias por cliente), **pessoa** (e-mail e senha, papel, sessão que o servidor pode derrubar)
e **nós** (um token no `.env`). Veja a decisão no [ADR 0001](adr/0001-estrutura-simples.md).

---

## 6. Passo 2: tudo parte do cliente autenticado

Não há um segundo middleware de "carregar cliente": depois do `AutenticaCliente` (ou do `AutenticaPortal`), o cliente já está na "mochila" da requisição.
Nos controllers, [Controller.php](../app/Http/Controllers/Controller.php) tem um atalho que a lê:
```php
$this->cliente($request)   // o Cliente autenticado
```

### Eloquent como barreira de segurança
Em todo o código, a consulta **parte do cliente**:
```php
$cliente->remessas()->findOrFail($id)      // só acha se a remessa for DESTE cliente; senão, 404
```
`$cliente->remessas()` é uma **relação** ([Cliente.php](../app/Models/Cliente.php)): o Eloquent acrescenta `WHERE cliente_id = ...` sozinho.
Remessa de outro cliente dá 404, igual a uma que não existe: ninguém descobre se o id existe.
Esquecer o filtro é o erro clássico de vazamento entre clientes; por isso a regra é **nunca começar de `Remessa::find(...)` nos controllers**.

O mesmo vale para as credenciais das transportadoras: `$cliente->credenciais()` devolve só as **deste cliente**. É por isso que cada cliente cota e envia com o contrato dele.

O banco reforça a regra: `remessas` e `cotacoes` têm `cliente_id`, e a remessa só pode apontar para uma cotação do mesmo cliente (chave estrangeira composta `(cliente_id, cotacao_id)`).

---

## 7. Controller, Form Request, Service e Resource

O padrão se repete em toda rota. Vamos seguir **`POST /cotacoes`** inteiro.

### 7.1 Form Request: validar antes de entrar no controller
[CotacaoRequest.php](../app/Http/Requests/CotacaoRequest.php) estende [ApiFormRequest](../app/Http/Requests/ApiFormRequest.php#L15). Quando ele aparece como parâmetro do controller, o Laravel:

1. cria o objeto;
2. roda [`prepareForValidation()`](../app/Http/Requests/CotacaoRequest.php#L10) (aqui: tira o hífen do CEP);
3. roda [`rules()`](../app/Http/Requests/CotacaoRequest.php#L22) (as regras: `'cep_destino' => ['required', 'string', 'regex:/^\d{8}$/']` etc.);
4. se falhar, lança `ValidationException` → **422** automático. **O controller nem é chamado.**

A classe-base [`ApiFormRequest::after()`](../app/Http/Requests/ApiFormRequest.php#L24) acrescenta uma regra para todos: **campo que não existe em `rules()` é recusado**
(digitou `valor_declarado_centavo` sem o "s"? Erro 422, em vez de passar em silêncio com valor vazio).

Depois do `validated()`, `$request->validated()` devolve **só** os campos validados. É por isso que nunca usamos `$request->all()`: campo estranho nunca entra.
[`CotacaoRequest::validated()`](../app/Http/Requests/CotacaoRequest.php#L41) ainda filtra os campos de cada volume.

### 7.2 Controller: fino
[CotacaoController::store](../app/Http/Controllers/CotacaoController.php#L15):
```php
$cotacao = $service->cotar($this->cliente($request), $request->validated());
return (new CotacaoResource($cotacao))->response()->setStatusCode(201);
```
Duas linhas: chama o service e formata. Sem `if`, sem consulta ao banco.

### 7.3 Resource: o formato da resposta
[CotacaoResource.php](../app/Http/Resources/CotacaoResource.php) decide **quais campos** saem e com que nome. O model tem colunas que não devem vazar (por exemplo, `hash_requisicao` na remessa).
Separar o formato da resposta do model permite mudar o banco sem quebrar o contrato da API.

### 7.4 Service: onde mora a regra
O próximo capítulo detalha o [`CotacaoService`](../app/Services/CotacaoService.php#L21).

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
1. credenciais ATIVAS DESTE CLIENTE            → nenhuma? erro 422 'sem_transportadoras'
2. para cada credencial:
      $t = Transportadoras::criar($credencial)   → cria Jadlog ou Correios
      $t->pedidosDeCotacao($dados)               → "o que eu preciso chamar?"
3. $this->http->enviarEmParalelo(...)          → manda TODAS as chamadas juntas
4. para cada transportadora:
      $t->lerCotacao($dados, $respostasDela)     → opções; se der problema, vai para "falhas"
5. ordena por valor e salva a Cotacao (resultados em jsonb, expira em 15 min)
```
O `try/catch` em volta de cada transportadora é o que garante: **falhou a Jadlog? A cotação sai só com os Correios**, e a falha aparece em `falhas`.

### Quem cria a transportadora certa: [Transportadoras.php](../app/Transportadoras/Transportadoras.php)
- `LISTA` mapeia o código (`'jadlog'`) para a classe. **É o único lugar a editar para registrar uma transportadora nova.**
- `criar()` lê `$credencial->transportadora` e cria a classe certa (seção 2, "onde não usamos DI").
- `catalogo()` monta a lista que o formulário do portal usa (veja a seção 9, "O catálogo de campos").

### As chamadas HTTP: [ClienteHttp.php](../app/Transportadoras/ClienteHttp.php)
Todo HTTP para transportadora passa por aqui. Ele garante, para todas:
- **timeout** curto (3 s), sem seguir redirecionamentos, TLS verificado;
- **histórico** em `chamadas_transportadoras`, com dados sensíveis **mascarados** ([Mascaramento.php](../app/Support/Mascaramento.php): tira token, CPF, e-mail etc.).

[`enviarEmParalelo`](../app/Transportadoras/ClienteHttp.php#L52) usa `Http::pool(...)` do Laravel, que dispara várias requisições ao mesmo tempo e espera todas.
Uma requisição que dá timeout volta como `null` (em vez de explodir tudo).

### Uma transportadora por dentro: [Jadlog.php](../app/Transportadoras/Jadlog.php)
Leia nesta ordem:
1. [`regras()`](../app/Transportadoras/Jadlog.php#L19) e [`perguntas()`](../app/Transportadoras/Jadlog.php#L41): que dados o cadastro dela exige (seção 9).
2. [Construtor](../app/Transportadoras/Jadlog.php#L71): recebe `$credencial` (token etc.), `ClienteHttp` e o `$clienteId` (só para o histórico).
3. [`pedidosDeCotacao`](../app/Transportadoras/Jadlog.php#L84): monta o JSON do PDF da Jadlog. Calcula o peso (maior entre real e cubado: [`pesoEmKg`](../app/Transportadoras/Jadlog.php#L438)) e divide em chamadas de no máximo 3 modalidades.
4. [`lerCotacao`](../app/Transportadoras/Jadlog.php#L121): converte `vltotal` (reais) em **centavos** com [`Dinheiro::deReais`](../app/Support/Dinheiro.php) e identifica os erros.

**Por que centavos?** `0.1 + 0.2` em ponto flutuante dá `0.30000000000000004`. Dinheiro em `float` acumula erro. Em inteiro de centavos, `10 + 20 = 30`, sempre.

### Erros das transportadoras: [TransportadoraIndisponivel.php](../app/Transportadoras/TransportadoraIndisponivel.php)
Uma exceção com um `$motivo` curto e seguro (`sem_resposta`, `credencial_invalida`, `erro_na_transportadora`, `resposta_invalida`, `sem_cobertura`).
O método estático `motivoDaResposta()` olha uma resposta HTTP e diz se ela indica problema da transportadora (401/403, 5xx, nulo) ou se pode ser lida normalmente.
O cliente **só vê esse motivo**; o texto original da transportadora fica só no histórico de chamadas.

### Os Correios: [Correios.php](../app/Transportadoras/Correios.php)
Diferenças que justificam o desenho em dois passos:
- Preço e prazo são **duas APIs separadas** (então dois tipos de chamada no mesmo `pedidosDeCotacao`).
- O preço é por **objeto**: um pedido com 2 caixas = 2 consultas de preço somadas.
- Precisa de um **token** que dura ~24h: [`token()`](../app/Transportadoras/Correios.php#L462) guarda no cache (Redis) e só pede outro quando vence.
  O cache é por credencial (ou seja, por cliente): um cliente nunca usa o token do outro.

---

## 9. Cadastro de clientes e das credenciais

### Clientes, chaves e operadores
- **Cliente + dono** (pela administração, feito por nós): [Admin\ClienteController::store](../app/Http/Controllers/Admin/ClienteController.php) (`POST /admin/clientes`) → [`ClienteService::criar`](../app/Services/ClienteService.php): cria o cliente e o usuário dono. A validação está em [ClienteRequest](../app/Http/Requests/ClienteRequest.php).
- O cliente **não** se cadastra sozinho: só quem tem o token de admin ([AutenticaAdmin](../app/Http/Middleware/AutenticaAdmin.php)) cria clientes.
- **Chaves de API e operadores** (pelo portal, feito pelo dono): [Portal\ChaveApiController](../app/Http/Controllers/Portal/ChaveApiController.php) → [`ChaveApiService`](../app/Services/ChaveApiService.php) e [Portal\UsuarioController](../app/Http/Controllers/Portal/UsuarioController.php) → [`UsuarioService`](../app/Services/UsuarioService.php).
- Depois de criado, o cliente consulta a conta pela API: [ContaController](../app/Http/Controllers/ContaController.php) (`GET /conta`, só leitura, nas duas portas).
  O formato da resposta está em [ClienteResource](../app/Http/Resources/ClienteResource.php) (nunca mostra segredo; hoje o campo do id ainda usa o nome antigo do modelo anterior).

### `ErroDeNegocio`: erros "previstos"
```php
throw new ErroDeNegocio('cotacao_expirada', 'A cotacao expirou. Faca uma nova.', 422);
```
Um código (para máquinas), uma mensagem (para pessoas) e o status HTTP. Qualquer service pode lançar; a seção 13 mostra como vira resposta.

### Credenciais das transportadoras (API e comando)
Pela API (só o **dono** no portal; qualquer chave de API): `PUT /transportadoras/{t}` ([TransportadoraController](../app/Http/Controllers/TransportadoraController.php), regras vindas de `Classe::regras()` no [TransportadoraRequest](../app/Http/Requests/TransportadoraRequest.php)) e `PATCH` para ligar/desligar; as duas formas gravam o contrato **do cliente logado**, pelo [`TransportadoraService`](../app/Services/TransportadoraService.php). Pelo terminal:
[ConfigurarTransportadora.php](../app/Console/Commands/ConfigurarTransportadora.php#L18) (`transportadora:configurar`):
1. acha a classe da transportadora em `Transportadoras::LISTA`;
2. percorre `Classe::perguntas()` e faz cada pergunta (`secret()` esconde o que você digita);
3. valida tudo com `Classe::regras()` (o mesmo estilo de regras dos Form Requests, usando o `Validator` direto);
4. salva pelo [`TransportadoraService`](../app/Services/TransportadoraService.php).

Como o comando serve a vários clientes, ele recebe o id do cliente como argumento (`transportadora:configurar jadlog <id-do-cliente>`); se existir só um cliente ativo, pode omitir.

#### O catálogo de campos (o formulário do portal)
`GET /transportadoras/catalogo` ([TransportadoraController::catalogo](../app/Http/Controllers/TransportadoraController.php)) chama
[`Transportadoras::catalogo()`](../app/Transportadoras/Transportadoras.php). Para cada classe em `LISTA` ele junta:
- `nome()`: o nome para mostrar ("Jadlog", "Correios");
- `perguntas()`: a lista de campos, na ordem. Além de `campo`, `rotulo`, `secreto`, `padrao` e `tipo`, cada pergunta pode ter
  `titulo` (nome na tela, quando o rótulo do terminal não serve), `ajuda` (dica) e `opcoes` (`['K' => 'texto']`, para `tipo => 'select'`);
- `regras()`: daqui sai o `obrigatorio` (o campo tem `required`?). **Não repita isso em `perguntas()`**: uma fonte só, para as duas nunca discordarem.

O `grupo` (`credenciais` ou `configuracao`) sai do começo do `campo`. O `tipo` vira `secreto` quando a pergunta tem `secreto => true`.
Exemplo de uma pergunta com tudo (da Jadlog):
```php
['campo' => 'configuracao.tipo_coleta', 'rotulo' => 'Tipo de coleta: K = ..., S = ...', 'padrao' => 'K',
 'titulo' => 'Tipo de coleta', 'tipo' => 'select', 'opcoes' => ['K' => 'A Jadlog coleta no lojista', 'S' => 'O lojista leva ate a Jadlog']],
```
**Por que no código e não numa tabela?** O hub só atende uma transportadora que tem classe de integração. Uma linha no banco sem classe não serviria para nada,
e a classe já sabe quais campos precisa. Assim, quem cria a classe ganha o formulário de graça (veja o ADR 0001).

#### Máscara e "mostrar" com senha
- [`TransportadoraResource`](../app/Http/Resources/TransportadoraResource.php) devolve `credenciais_mascaradas`: valor com 12+ caracteres = 6 bolinhas + 4 últimos; senão 8 bolinhas. Nunca o valor inteiro.
- [`Portal\CredencialController::revelar`](../app/Http/Controllers/Portal/CredencialController.php) (`POST /portal/transportadoras/{t}/credenciais`, só dono, dentro do grupo `papel:adm_tenant`):
  1. confere a senha enviada com `Hash::check` contra a senha do usuário logado; errada = `ErroDeNegocio('senha_incorreta', ..., 422)`;
  2. busca a credencial **do cliente logado** (`transportadora_nao_cadastrada`, 404, se não houver);
  3. grava no log (`Log::notice`: cliente, usuário e transportadora; **nunca** o segredo);
  4. devolve `credenciais` por inteiro.
  O limite `throttle:revelar` (`LIMITE_REVELAR_MIN`, padrão 5 por minuto por usuário) protege contra quem tenta adivinhar a senha por aqui.
  A rota **não** está no grupo da chave de API, de propósito.

#### Como adicionar uma transportadora (o que aparece no formulário)
1. Crie a classe (copie `Jadlog.php`) com `nome()`, `perguntas()` e `regras()`, mais as chamadas de cotação e remessa.
2. Registre em `Transportadoras::LISTA`.
3. Pronto: `GET /transportadoras/catalogo` já lista a nova transportadora e os campos dela, e as rotas `/transportadoras/{codigo}` a aceitam. Nada a mudar no front.

No model [CredencialTransportadora](../app/Models/CredencialTransportadora.php) os segredos usam `'credenciais' => 'encrypted:array'`:
o Laravel **criptografa ao gravar e descriptografa ao ler**, com a `APP_KEY`. No banco aparece só texto ilegível.
E `$hidden = ['credenciais']` garante que nem um `toArray()` distraído os exponha.

---

## 10. Remessa: criar sem duplicar (idempotência)

### O problema
O backend do cliente chama `POST /remessas`, a rede cai antes da resposta chegar, e ele **repete a chamada**. Sem cuidado, criaríamos **duas remessas** (e pagaríamos dois fretes).

### A solução: `Idempotency-Key`
O cliente manda um header com uma chave única por remessa (o id do pedido serve). Regra:
- **mesma chave + mesmo pedido** → devolve a remessa que já existe (200), sem criar outra;
- **mesma chave + pedido diferente** → erro 422.

### Os arquivos
1. [RemessaRequest.php:14](../app/Http/Requests/RemessaRequest.php#L14): em `prepareForValidation`, o header vira um campo (`$this->merge(['idempotency_key' => $this->header('Idempotency-Key')])`) para ser validado como qualquer outro.
2. [RemessaController::store](../app/Http/Controllers/RemessaController.php#L24): chama o service e responde 201 (criou) ou 200 (repetição), com o header `Idempotent-Replayed`.
3. O trabalho: [`RemessaService::criarEnvio`](../app/Services/RemessaService.php#L38).

### `criarEnvio` passo a passo
```
 1. hash = hashDoPedido(...)                 resumo (sha256) do pedido, igual para pedidos iguais
 2. já existe remessa com essa chave?        → reaproveitar(): mesmo hash = devolve; hash diferente = 422
 3. cotação (deste cliente) existe, não expirou, e a opção   → erros claros (404/422) se algo estiver errado
    escolhida (transportadora + serviço)
    está nela?
 4. CEPs da remessa = CEPs da cotação?       → senão 422 (o preço foi calculado para outros CEPs)
 5. reservar()                               → grava a remessa com status "criando"
 6. criarNaTransportadora()                  → chama a Jadlog/Correios de verdade (com as credenciais do cliente)
 7. atualiza para "criada" + códigos         → guarda etiqueta no Storage, se vier
```

### Por que "reservar" antes de chamar a transportadora (passo 5)?
Se chamássemos a transportadora primeiro e só depois gravássemos, duas requisições simultâneas com a mesma chave **criariam dois pedidos** na transportadora antes de qualquer uma gravar no banco.
Gravando **primeiro** (status `criando`), a constraint única `(cliente_id, idempotency_key)` do banco garante que **só uma passa**. A outra recebe o erro do banco.

Veja [`reservar`](../app/Services/RemessaService.php#L257):
```php
try {
    return DB::transaction(fn () => $cliente->remessas()->create(...));
} catch (UniqueConstraintViolationException) {
    return null;     // outra requisição chegou primeiro
}
```
E o `criarEnvio` trata `null` como "reaproveitar a que já existe".

> **Por que `DB::transaction` ao redor de uma única inserção?** No Postgres, depois de um erro, a transação em andamento fica "quebrada" e recusa comandos. Quando já estamos dentro de uma transação (nos testes, por exemplo), o Laravel transforma a interna num *savepoint*, e o erro desfaz só aquele trecho.

### Se a transportadora falhar (passo 6)
[`criarNaTransportadora`](../app/Services/RemessaService.php#L271) captura a exceção, **apaga a reserva** (`$remessa->delete()`) e relança.
Assim a chave fica livre e o cliente pode tentar de novo com a mesma `Idempotency-Key`.
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

- **Despachar** ([`RemessaService::despachar`](../app/Services/RemessaService.php#L174)): só muda o status (`criada → despachada`), **sem chamar a transportadora**.
  Valida com o enum: `if (! $remessa->status->podeDespachar()) throw new ErroDeNegocio(...)` (409).
- **Cancelar** ([`cancelar`](../app/Services/RemessaService.php#L187)): valida o status, **chama a transportadora**, e só então muda o status. Se ela recusar, a remessa fica como estava.
  Cancelar algo já cancelado na transportadora **não é erro** (o resultado que queremos já existe).
- **Etiqueta** ([`etiqueta`](../app/Services/RemessaService.php#L209)): se já temos o PDF no disco, devolve; se não, pede à transportadora e guarda.
  O PDF **nunca vai para o banco**: usamos `Storage::disk(...)` (disco configurável em `config/filesystems.php`; trocar por S3 é só mudar uma variável).

Cada transportadora implementa isso à sua maneira (`criarRemessa`, `cancelarRemessa`, `etiqueta` na interface):
- **Jadlog**: o PDF vem junto da criação; cancelar usa o `shipmentId`.
- **Correios**: cada volume vira uma pré-postagem; a etiqueta é gerada depois, de forma assíncrona.

---

## 12. Devolução

[`criarDevolucao`](../app/Services/RemessaService.php#L111) usa a **mesma estrutura** (e a mesma tabela `remessas`, com `tipo = 'devolucao'`). As diferenças:
- só vale para remessa **entregue**;
- **inverte** remetente e destinatário: `'remetente' => $origem->destinatario`, `'destinatario' => $origem->remetente`;
- usa a mesma transportadora e serviço da remessa original;
- quem paga o frete (tomador, na Jadlog) continua sendo o titular do contrato (o próprio cliente).

Reaproveitar o mesmo mecanismo (`reservar`, `criarNaTransportadora`) é intencional: a devolução ganhou idempotência e tratamento de falhas de graça.

---

## 13. Como os erros chegam ao cliente

Em qualquer ponto você pode fazer `throw`. Quem transforma isso em resposta é o [RenderizaExcecao](../app/Exceptions/RenderizaExcecao.php), registrado em [bootstrap/app.php](../bootstrap/app.php).
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

Sem conta real nas transportadoras, como testar? Com `TRANSPORTADORAS_MODO=mock`, o [AppServiceProvider](../app/Providers/AppServiceProvider.php#L14) chama [`SimuladorHttp::instalar()`](../app/Transportadoras/Mock/SimuladorHttp.php#L33), que usa o `Http::fake(...)` do Laravel:

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
- [TestCase.php](../tests/TestCase.php): helpers para montar cenários (`criarCliente()`, `configurarJadlog()`, `criarCotacaoComOpcoes()`, `comChave()`...). Eles criam dados já no formato correto, para o teste focar no que importa.
- Os testes chamam a API de verdade (`$this->postJson(url, dados, headers)`) e conferem status e corpo. Eles usam o simulador.
- Tipos: **Unit** (sem banco: `Mascaramento`, `Dinheiro`...) e **Feature** (fluxos completos).
- Há testes "de regra do projeto", como o que garante que **nenhum código fora de `app/Transportadoras/` cita Jadlog ou Correios** pelo nome.

Dica para ler um teste: o nome em português diz a regra (`it('repetir a chamada com a mesma chave devolve a mesma remessa...')`). Comece lendo só os nomes.

---

## 16. Exercício: siga um `POST /remessas`

Abra os arquivos e marque cada parada. Chamada: `POST /api/v1/remessas`, com o header `Idempotency-Key`.

1. [`bootstrap/app.php`](../bootstrap/app.php): `RequestId`, `ForcaJson` rodam primeiro (global).
2. [`routes/api.php`](../routes/api.php): a rota casa; o grupo aplica `auth.cliente` e a rota aplica `throttle:remessas`. (No portal: grupo `portal` → `auth.portal`.)
3. [`AutenticaCliente`](../app/Http/Middleware/AutenticaCliente.php): valida a chave de API ([`ChaveApiService::autenticar`](../app/Services/ChaveApiService.php)) → `cliente` na mochila.
4. Throttle: conta a requisição para este cliente (Redis).
5. (Sem terceiro middleware: o cliente já está identificado.)
6. [`RemessaRequest`](../app/Http/Requests/RemessaRequest.php#L12): normaliza e valida (CPF/CNPJ, UF, tamanhos); campo desconhecido = 422.
7. [`RemessaController::store`](../app/Http/Controllers/RemessaController.php#L24): chama o service.
8. [`RemessaService::criarEnvio`](../app/Services/RemessaService.php#L38): idempotência → validações → `reservar` → `criarNaTransportadora`.
9. [`Transportadoras::criar`](../app/Transportadoras/Transportadoras.php#L27) → `Jadlog::criarRemessa` ([L233](../app/Transportadoras/Jadlog.php#L233)) → [`ClienteHttp::enviar`](../app/Transportadoras/ClienteHttp.php#L28) (HTTP + histórico mascarado).
10. Volta ao service: atualiza para `criada`, guarda a etiqueta.
11. [`RemessaResource`](../app/Http/Resources/RemessaResource.php): monta o JSON; o controller responde 201.
12. [`CabecalhosDeSeguranca`](../app/Http/Middleware/CabecalhosDeSeguranca.php) acrescenta os headers; [`RequestId`](../app/Http/Middleware/RequestId.php) acrescenta `X-Request-Id`.

Agora tente responder (a resposta está no capítulo indicado):
- O que acontece se a Jadlog der timeout no passo 9? *(cap. 10)*
- Por que repetir a chamada não cria uma segunda remessa? *(cap. 10)*
- Por que o cliente B não consegue ver a remessa do cliente A, mesmo sabendo o id dela? *(cap. 6)*
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
| **Eloquent / relação** | Os models; `$cliente->remessas()` gera a consulta já filtrada pelo cliente |
| **`casts()`** | Conversão automática de coluna: `encrypted:array`, enum, datetime |
| **Constraint única** | Regra do banco que impede duplicata; é a defesa final contra corrida de requisições |
| **Idempotência** | Repetir a mesma operação não muda o resultado além da primeira vez |
| **Enum** | Tipo com valores fixos (`StatusRemessa::Criada`), em vez de texto solto |
| **Mock / `Http::fake`** | Resposta falsa para uma chamada HTTP, para não depender de serviço externo |
