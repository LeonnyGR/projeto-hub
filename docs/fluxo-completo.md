# Fluxo completo: da digitação do CEP à devolução

Este guia segue **uma compra de verdade**, passo a passo, mostrando o que o backend do cliente chama e **qual código roda por dentro** em cada etapa.
Na segunda parte, ensina a **implementar uma transportadora nova** (o trabalho do trainee).

Como usar: leia um passo, abra os arquivos linkados e acompanhe. Os links apontam para o início de cada função.
Para os conceitos de Laravel que aparecem (injeção de dependência, middleware, Form Request...), veja o [guia por camadas](guia-do-codigo.md).

---

## Índice

**Parte 1: a jornada de uma compra**
- [A cena](#a-cena)
- [O que toda chamada faz antes de chegar no código do passo](#o-que-toda-chamada-faz-antes-de-chegar-no-código-do-passo)
- [Passo 0: preparação (feita uma vez)](#passo-0-preparação-feita-uma-vez)
- [Passo 1: o cliente digita o CEP](#passo-1-o-cliente-digita-o-cep)
- [Passo 2: ver pontos de retirada (opcional)](#passo-2-ver-pontos-de-retirada-opcional)
- [Passo 3: cotar o frete do carrinho](#passo-3-cotar-o-frete-do-carrinho)
- [Passo 4: o cliente escolhe o frete e fecha o pedido](#passo-4-o-cliente-escolhe-o-frete-e-fecha-o-pedido)
- [Passo 5: a loja imprime a etiqueta](#passo-5-a-loja-imprime-a-etiqueta)
- [Passo 6: a loja entrega o pacote (despachar)](#passo-6-a-loja-entrega-o-pacote-despachar)
- [Passo 7 (alternativo): o cliente desiste, cancelar](#passo-7-alternativo-o-cliente-desiste-cancelar)
- [Passo 8: o produto chegou e o cliente quer devolver](#passo-8-o-produto-chegou-e-o-cliente-quer-devolver)
- [Resumo: quem faz o quê](#resumo-quem-faz-o-quê)

**Parte 2: implementar uma transportadora nova**
- [Visão geral e checklist](#visão-geral-e-checklist)
- [Etapa A a H, passo a passo](#etapa-a-estude-a-documentação-da-transportadora)
- [Modelo de classe para copiar](#modelo-de-classe-para-copiar)
- [Os formatos de dados](#os-formatos-de-dados)
- [Que erro lançar em cada situação](#que-erro-lançar-em-cada-situação)
- [Erros que já cometemos (evite)](#erros-que-já-cometemos-evite)
- [Como saber que terminou](#como-saber-que-terminou)

---

# Parte 1: a jornada de uma compra

## A cena

A **Maria** entra no site da **Loja do João** (um **cliente** do hub) e escolhe um produto. Ela digita o CEP de entrega, vê as opções de frete,
escolhe uma e finaliza a compra. Depois a loja imprime a etiqueta, entrega o pacote à transportadora e, dias depois, a Maria pede devolução.

Quem chama a API é o **backend da Loja do João** (nunca o navegador da Maria), sempre com a **chave de API** da loja
(`Authorization: Bearer fs_<prefixo>.<segredo>`). O hub descobre quem é o cliente por essa chave e usa **as credenciais de Jadlog e Correios que a própria loja cadastrou**.
Não há id de loja na URL, e outro cliente do hub nunca vê os dados da loja.

```
Maria ─► site da loja ─► backend da loja ──(chave de API)──► FRETE HUB ──► Jadlog / Correios
```

O hub tem **três portas de entrada**, e este guia segue a porta 2 (a do backend da loja):

| Porta | Quem usa | Prefixo | Autenticação | Middleware |
|---|---|---|---|---|
| 1. Administração | a TI do hub | `/api/v1/admin/...` | `Bearer <ADMIN_API_TOKEN>` | [`AutenticaAdmin`](../app/Http/Middleware/AutenticaAdmin.php) |
| 2. Chave de API | o backend do cliente | `/api/v1/...` | `Bearer fs_<prefixo>.<segredo>` | [`AutenticaCliente`](../app/Http/Middleware/AutenticaCliente.php) |
| 3. Portal | o dono e os operadores do cliente | `/api/v1/portal/...` | e-mail + senha, cookie de sessão | [`AutenticaPortal`](../app/Http/Middleware/AutenticaPortal.php) (+ [`ExigePapel`](../app/Http/Middleware/ExigePapel.php) nas rotas de dono) |

As portas 2 e 3 chamam **os mesmos controllers**. Tudo o que você ler abaixo vale para as duas; muda só quem está autenticado.

## O que toda chamada faz antes de chegar no código do passo

Todas as chamadas passam, **nesta ordem**, pelas mesmas peças. Entenda isso uma vez e não precisa repetir em cada passo:

| # | Peça | O que faz | Se falhar |
|---|---|---|---|
| 1 | [`RequestId`](../app/Http/Middleware/RequestId.php#L18) | Dá um número à requisição (vai na resposta e em todos os logs) | n/a |
| 2 | [`ForcaJson`](../app/Http/Middleware/ForcaJson.php) | Garante respostas em JSON | n/a |
| 3 | [`AutenticaCliente`](../app/Http/Middleware/AutenticaCliente.php) (porta 2) ou [`AutenticaPortal`](../app/Http/Middleware/AutenticaPortal.php) (porta 3) | Chave de API: confere `Authorization: Bearer <chave>`. Portal: lê o usuário da sessão (cookie). Nos dois casos deixa o cliente na requisição | **401** |
| 3b | [`ExigePapel`](../app/Http/Middleware/ExigePapel.php) (só em algumas rotas do portal) | Libera só o dono (`adm_tenant`): chaves de API, operadores e `PUT/PATCH /transportadoras` | **403** |
| 4 | `throttle:...` | Limite de chamadas por minuto, por cliente | **429** |
| 5 | **Form Request** | Valida o corpo da requisição (se a rota tiver um) | **422** |
| 6 | Controller → Service | O código do passo (é o que a gente detalha abaixo) | varia |
| 7 | [`RenderizaExcecao`](../app/Exceptions/RenderizaExcecao.php) | Se algo lançou exceção, transforma em JSON padrão | n/a |
| 8 | [`CabecalhosDeSeguranca`](../app/Http/Middleware/CabecalhosDeSeguranca.php) | Acrescenta headers de segurança na resposta | n/a |

As rotas e os middlewares de cada uma estão em [routes/api.php](../routes/api.php); os apelidos (`auth.cliente`...) em [bootstrap/app.php](../bootstrap/app.php).

Um atalho que os controllers usam ([Controller.php](../app/Http/Controllers/Controller.php)): `$this->cliente($request)` devolve o cliente que o middleware 3 deixou na requisição. Toda consulta parte dele (`$cliente->remessas()->findOrFail($id)`), e é isso que garante o isolamento: dado de outro cliente dá 404.

---

## Passo 0: preparação (feita uma vez)

Antes de a Maria comprar qualquer coisa, a TI do hub e a loja fazem três coisas:

**a) A TI cadastra o cliente e o dono dele** (API de administração, com o token de admin do `.env`):
```bash
curl -X POST http://127.0.0.1:8090/api/v1/admin/clientes \
  -H "Authorization: Bearer $ADMIN_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"nome": "Loja do João", "cnpj": "12.345.678/0001-95",
       "dono": {"nome": "João da Silva", "email": "joao@lojadojoao.com.br", "password": "uma-senha-com-10-ou-mais"}}'
```
Código: [AutenticaAdmin](../app/Http/Middleware/AutenticaAdmin.php) (confere o token) → [Admin\ClienteController::store](../app/Http/Controllers/Admin/ClienteController.php) → [`ClienteService::criar`](../app/Services/ClienteService.php),
que cria o cliente **e** o usuário dono (`adm_tenant`) numa transação só. A TI entrega ao João o e-mail e a senha provisória.

**b) O João (dono) entra no portal e cria a chave de API do backend da loja:**
```bash
curl -s -c cookies.txt -X POST http://127.0.0.1:8090/api/v1/portal/login -H "Content-Type: application/json" \
  -d '{"email": "joao@lojadojoao.com.br", "password": "uma-senha-com-10-ou-mais"}'

curl -s -b cookies.txt -X POST http://127.0.0.1:8090/api/v1/portal/chaves -H "Content-Type: application/json" -d '{"nome": "producao"}'
# a resposta traz "chave": "fs_3fa91c0b2d4e.k8Jx..."  (aparece UMA vez: guarde no cofre do backend da loja)
```
Código: [Portal\SessaoController::login](../app/Http/Controllers/Portal/SessaoController.php) (confere a senha e abre a sessão) → [Portal\ChaveApiController::store](../app/Http/Controllers/Portal/ChaveApiController.php)
(só o dono: `ExigePapel`) → [`ChaveApiService::criar`](../app/Services/ChaveApiService.php). O banco guarda só o **hash** do segredo da chave.
Daí em diante o backend da loja chama a API com `-H "Authorization: Bearer $CHAVE"`. Se o João quiser, cadastra também **operadores** (`POST /portal/usuarios`) para a equipe, via [Portal\UsuarioController](../app/Http/Controllers/Portal/UsuarioController.php) e [`UsuarioService`](../app/Services/UsuarioService.php).

**c) O dono cadastra o contrato dele com as transportadoras** (pela tela do portal, que usa a API; os segredos ficam criptografados). A tela funciona em 4 passos:
```
1. GET  /api/v1/portal/transportadoras/catalogo        → quais transportadoras existem e quais campos cada uma pede (a tela desenha o formulário)
2. PUT  /api/v1/portal/transportadoras/correios        → o dono preenche e a tela envia
3. GET  /api/v1/portal/transportadoras                 → lista o cadastrado, com "credenciais_mascaradas" ("••••••1234")
4. POST /api/v1/portal/transportadoras/correios/credenciais   { "password": "<senha do dono>" }   → só se ele clicar em "mostrar"
```
O catálogo vem do **código** ([`Transportadoras::catalogo()`](../app/Transportadoras/Transportadoras.php), que lê `nome()`, `perguntas()` e `regras()` de cada classe), então a tela não tem campos fixos.
O passo 4 pede a senha de novo, só funciona para o dono, tem limite (`LIMITE_REVELAR_MIN`, 5 por minuto) e fica registrado no log (nunca o segredo):
[Portal\CredencialController::revelar](../app/Http/Controllers/Portal/CredencialController.php).
O corpo do passo 2 (o mesmo pela chave de API, sem o `/portal`):
```
PUT /api/v1/transportadoras/correios
{ "credenciais": { "usuario": "…", "senha": "…", "cartao_postagem": "…" },
  "configuracao": { "contrato": "…", "servicos": [ {"codigo": "03220", "nome": "SEDEX"}, {"codigo": "04669", "nome": "PAC"} ] } }
```
Código: [TransportadoraController::catalogo e ::update](../app/Http/Controllers/TransportadoraController.php) → [`TransportadoraService::configurar`](../app/Services/TransportadoraService.php).
A TI também pode fazer o mesmo pelo terminal: `docker/scripts/artisan.sh transportadora:configurar correios <id-do-cliente>`
([ConfigurarTransportadora](../app/Console/Commands/ConfigurarTransportadora.php)). **Aqui já aparece o primeiro ponto onde uma transportadora nova entra:**
o cadastro valida com [`regras()`](../app/Transportadoras/Jadlog.php) da classe da transportadora, e o comando pergunta o que a classe mandar em [`perguntas()`](../app/Transportadoras/Jadlog.php).
(Detalhe: não existe um comando por transportadora; o comando lê as perguntas da classe.)

> A **chave de API nunca vai para o navegador**. A tela do portal usa login e cookie (`HttpOnly`, o JavaScript não lê); só o **backend** da loja guarda a chave.

---

## Passo 1: o cliente digita o CEP

**Cena:** a Maria digita `35432065`; o site precisa preencher rua, bairro e cidade.

**Chamada do backend da loja:**
```
GET /api/v1/enderecos/35432065
```
(O CEP não depende de contrato com transportadora, mas a chamada continua exigindo autenticação: a chave de API, no header `Authorization: Bearer ...`.)

**Por dentro:**
1. [`routes/api.php`](../routes/api.php) → [`EnderecoController::show`](../app/Http/Controllers/EnderecoController.php): tira máscara do CEP e recusa se não tiver 8 dígitos (422).
2. [`ConsultasService::endereco`](../app/Services/ConsultasService.php#L21) → [`ViaCep::buscar`](../app/Services/ViaCep.php#L31).
3. O `ViaCep` consulta o **ViaCEP** (serviço público) pelo [`ClienteHttp`](../app/Transportadoras/ClienteHttp.php#L25) e converte para o nosso formato. Respostas boas ficam em **cache por 7 dias** (para não consultar sempre).
4. CEP inexistente → 404 `cep_nao_encontrado`. ViaCEP fora do ar → 502.

**Resposta:**
```json
{ "data": { "cep": "35432065", "logradouro": "Rua Olaria de Baixo", "bairro": "Anna Florência", "cidade": "Ponte Nova", "uf": "MG", "complemento": null } }
```

**Transportadora nova aqui?** Não. O CEP vem do ViaCEP, não das transportadoras.

> Observação: o arquivo `ViaCep.php` hoje contém uma alteração de depuração (retorna o JSON cru e ignora cache e erros). Veja a pergunta pendente sobre restaurá-lo.

---

## Passo 2: ver pontos de retirada (opcional)

**Cena:** a loja oferece "retirar em um ponto" e quer listar os pontos perto da Maria.

**Chamada:**
```
GET /api/v1/pontos-retirada?cep=35432065
```

**Por dentro:**
1. [`PontoRetiradaRequest`](../app/Http/Requests/PontoRetiradaRequest.php) valida (`cep` obrigatório; `transportadora` e `tipos` opcionais).
2. [`PontoRetiradaController::index`](../app/Http/Controllers/PontoRetiradaController.php) → [`ConsultasService::pontosDeRetirada`](../app/Services/ConsultasService.php#L32).
3. O service pega as **credenciais ativas do cliente** e, para cada transportadora, pergunta: "você implementa [`PontosDeRetirada`](../app/Transportadoras/PontosDeRetirada.php)?" (`instanceof`).
   Quem não implementa é ignorada. Quem implementa é consultada, e cada ponto ganha o campo `transportadora`.
4. Se uma falhar, as outras continuam; a falha aparece em `falhas`.

**Hoje:** só a [Jadlog](../app/Transportadoras/Jadlog.php#L17) implementa.

**Transportadora nova aqui?** **Opcional.** Se ela tiver pontos de retirada, a classe implementa a interface `PontosDeRetirada`, e este passo já passa a incluí-la, **sem mexer em mais nada**.

---

## Passo 3: cotar o frete do carrinho

**Cena:** a Maria tem 1 caixa no carrinho. O site precisa mostrar as opções de frete com preço e prazo.

**Chamada:**
```
POST /api/v1/cotacoes
{ "cep_origem": "01310100", "cep_destino": "35432065", "valor_declarado_centavos": 15000,
  "volumes": [{ "peso_gramas": 1200, "altura_cm": 10, "largura_cm": 20, "comprimento_cm": 30 }] }
```

**Várias origens (vários vendedores)?** O `cep_origem` vai em toda cotação, então o B2C faz **uma chamada `POST /cotacoes` por origem**, cada uma com o CEP do vendedor. Exemplo: dois vendedores, um em 01310100 e outro em 30130010, mandando para o mesmo cliente = duas cotações, com dois `id` diferentes. O B2C junta as respostas na tela dele, e ao fechar o pedido cria uma remessa com o `cotacao_id` de cada origem.

**Por dentro, na ordem:**
1. [`CotacaoRequest`](../app/Http/Requests/CotacaoRequest.php#L7) valida (CEP com 8 dígitos, pesos e medidas dentro dos limites, no máximo 10 volumes). Campo desconhecido = 422.
2. [`CotacaoController::store`](../app/Http/Controllers/CotacaoController.php#L15) chama [`CotacaoService::cotar`](../app/Services/CotacaoService.php#L28).
3. O service faz **cinco coisas**:
   1. Pega as credenciais **ativas deste cliente**. Nenhuma? 422 `sem_transportadoras`.
   2. Para cada credencial, [`Transportadoras::criar`](../app/Transportadoras/Transportadoras.php#L27) cria a classe certa (`Jadlog`, `Correios`...) e chama **`pedidosDeCotacao($dados)`**.
      Essa função **só descreve** as chamadas HTTP que precisa fazer (URL, headers, corpo). Não envia nada.
   3. O [`ClienteHttp::enviarEmParalelo`](../app/Transportadoras/ClienteHttp.php#L52) envia **todas as chamadas de todas as transportadoras ao mesmo tempo** (`Http::pool`), cada uma com timeout de 3 s.
      Cada chamada é gravada no histórico (`chamadas_transportadoras`) com dados sensíveis mascarados.
   4. Cada transportadora recebe **as suas respostas** em **`lerCotacao($dados, $respostas)`** e devolve as opções (`servico_codigo`, `servico_nome`, `valor_centavos`, `prazo_dias`).
      Se der problema, ela **lança** `TransportadoraIndisponivel` e o service anota em `falhas`; **as outras seguem**.
   5. Ordena do mais barato ao mais caro e salva a `Cotacao` (resultados em `jsonb`, expira em 15 min).
4. [`CotacaoResource`](../app/Http/Resources/CotacaoResource.php) monta o JSON.

**Resposta (resumo):**
```json
{ "data": { "id": "…", "expira_em": "…",
  "opcoes": [ { "transportadora": "correios", "servico_codigo": "03220", "servico_nome": "SEDEX", "valor_centavos": 1240, "prazo_dias": 3 }, … ],
  "falhas": [ { "transportadora": "jadlog", "motivo": "sem_resposta" } ] } }
```

**Por que dois métodos (`pedidosDeCotacao` + `lerCotacao`) em vez de um `cotar()`?**
Se cada transportadora enviasse a própria chamada, uma esperaria a outra terminar (os tempos somariam). Separando "descrever" de "ler", quem envia é um só e manda tudo junto.

**Transportadora nova aqui?** **Sim**: implementa `pedidosDeCotacao` e `lerCotacao` (ver Parte 2). É a primeira coisa a fazer e a mais importante.

Um exemplo concreto, para ver o desenho funcionando: a [Jadlog](../app/Transportadoras/Jadlog.php#L17) monta o JSON do PDF dela (peso cubado, até 3 modalidades por chamada);
os [Correios](../app/Transportadoras/Correios.php#L23) precisam de **duas APIs** (preço e prazo) e de um **token** que dura 24 h (guardado no cache).

---

## Passo 4: o cliente escolhe o frete e fecha o pedido

**Cena:** a Maria escolhe "SEDEX, R$ 12,40, 3 dias" e finaliza. A loja precisa **criar a remessa** na transportadora.

**Chamada** (o header `Idempotency-Key` é obrigatório; use o id do pedido):
```
POST /api/v1/remessas        Idempotency-Key: pedido-1001
{ "cotacao_id": "…", "transportadora": "correios", "servico_codigo": "03220", "numero_pedido": "PED-1001",
  "remetente":    { "nome": "Loja do João Ltda", "documento": "11222333000181", "telefone": "3138810000", "endereco": { … } },
  "destinatario": { "nome": "Maria da Silva", "documento": "52998224725", "telefone": "31987654321", "endereco": { … } } }
```

**Por dentro:**
1. [`RemessaRequest`](../app/Http/Requests/RemessaRequest.php#L12) valida tudo: CPF/CNPJ com dígito verificador, UF, telefone, tamanhos dos campos. Aceita máscara (`11.222.333/0001-81`).
   O header vira um campo para ser validado como os outros.
2. [`RemessaController::store`](../app/Http/Controllers/RemessaController.php#L24) → [`RemessaService::criarEnvio`](../app/Services/RemessaService.php#L38). Em ordem:
   1. **Idempotência:** já existe remessa com essa chave? Mesmo pedido → devolve a que existe (200). Pedido diferente → 422.
   2. Valida a cotação: existe, é deste cliente, não expirou (404/422).
   3. A opção escolhida (transportadora + serviço) está na cotação? E os CEPs são os da cotação? (o preço foi calculado para eles; senão 422.)
   4. [`reservar`](../app/Services/RemessaService.php#L257): **grava** a remessa com status `criando` **antes** de falar com a transportadora.
      A constraint única `(cliente_id, idempotency_key)` do banco garante que, se duas chamadas iguais chegarem juntas, **só uma** passa.
   5. [`criarNaTransportadora`](../app/Services/RemessaService.php#L271): cria a classe da transportadora e chama **`criarRemessa($remessa)`**.
      - Deu certo: guarda `id_externo`, `codigo_rastreio` (um por volume) e, se vier, o PDF da etiqueta (no disco, nunca no banco). Status vira `criada`.
      - Deu erro: **apaga a reserva** (a chave fica livre para nova tentativa) e devolve 422 (`remessa_recusada`) ou 502 (`transportadora_indisponivel`).
3. [`RemessaResource`](../app/Http/Resources/RemessaResource.php) monta a resposta (201; ou 200 com `Idempotent-Replayed: true` se foi repetição).

**Por que gravar antes de chamar a transportadora?** Se chamássemos primeiro, duas requisições simultâneas criariam **dois pedidos** (e dois fretes pagos) antes de qualquer uma gravar.

**O que a transportadora recebe:** um array já pronto e **normalizado** (ver [formatos](#os-formatos-de-dados)): pessoas, volumes, serviço. Ela só **traduz** para o formato dela e envia.
Exemplos: a [Jadlog](../app/Transportadoras/Jadlog.php#L17) cria **um** pedido para todos os volumes; os [Correios](../app/Transportadoras/Correios.php#L23) criam **uma pré-postagem por volume** (cada uma com seu código de rastreio) e, se um volume falhar, cancelam os que já criou.

**Transportadora nova aqui?** **Sim**: implementa `criarRemessa`.

---

## Passo 5: a loja imprime a etiqueta

**Chamada:**
```
GET /api/v1/remessas/{id}/etiqueta        →  PDF
```

**Por dentro:** [`RemessaController::etiqueta`](../app/Http/Controllers/RemessaController.php#L49) → [`RemessaService::etiqueta`](../app/Services/RemessaService.php#L209):
1. Se o PDF já está no disco (a Jadlog devolve na criação), devolve.
2. Senão, chama **`etiqueta($remessa)`** da transportadora (os Correios geram sob demanda, de forma assíncrona), guarda no disco e devolve.
3. Se a transportadora devolve `null`: 404 `etiqueta_indisponivel`.

O disco é configurável (`ETIQUETAS_DISK`): hoje local, depois S3, sem mudar o código.

**Transportadora nova aqui?** **Sim**: implementa `etiqueta`. Se ela devolver o PDF **na criação**, `etiqueta()` pode só `return null;` e o PDF vai em `etiqueta_pdf` no `criarRemessa` (como a Jadlog).

---

## Passo 6: a loja entrega o pacote (despachar)

**Chamada:** `POST /api/v1/remessas/{id}/despachar`

**Por dentro:** [`RemessaService::despachar`](../app/Services/RemessaService.php#L174): confere no [enum `StatusRemessa`](../app/Enums/StatusRemessa.php#L14) se pode (`criada → despachada`) e **só muda o status**. Não chama a transportadora.

**Transportadora nova aqui?** Não.

---

## Passo 7 (alternativo): o cliente desiste, cancelar

**Chamada:** `POST /api/v1/remessas/{id}/cancelar`

**Por dentro:** [`RemessaService::cancelar`](../app/Services/RemessaService.php#L187):
1. O status permite? (só `criada` ou `despachada`; `em_transito` ou `entregue` = 409.)
2. Chama **`cancelarRemessa($remessa)`** da transportadora. **Só depois** de ela confirmar, muda o status para `cancelada`. Se ela recusar, a remessa continua como estava (422).
3. Cancelar algo que **já está cancelado** na transportadora **não é erro** (o resultado que queremos já existe).

**Transportadora nova aqui?** **Sim**: implementa `cancelarRemessa`.

---

## Passo 8: o produto chegou e o cliente quer devolver

**Cena:** a Maria recebeu o produto com defeito. A remessa está `entregue`.

**Chamada** (também com `Idempotency-Key`):
```
POST /api/v1/devolucoes        { "remessa_id": "…", "motivo": "Produto com defeito" }
```

**Por dentro:** [`DevolucaoController::store`](../app/Http/Controllers/DevolucaoController.php#L24) → [`RemessaService::criarDevolucao`](../app/Services/RemessaService.php#L111):
1. Mesma idempotência do passo 4.
2. A remessa original existe, é do cliente e está `entregue`? (senão 404 / 422.) Já existe devolução ativa para ela? (409.)
3. **Inverte** remetente e destinatário (quem recebeu agora envia; a loja recebe) e usa a **mesma transportadora e serviço**.
4. Reaproveita `reservar` e `criarNaTransportadora`, ou seja, chama o **mesmo `criarRemessa`** da transportadora, só com `tipo = 'devolucao'`.
   (A Jadlog, por exemplo, usa `servico = 2` / Dropoff quando `tipo` é devolução: [veja a Jadlog](../app/Transportadoras/Jadlog.php#L233).)

**Transportadora nova aqui?** **Sim, no mesmo `criarRemessa`**: o campo `$remessa['tipo']` diz se é `envio` ou `devolucao`. Se a transportadora trata devolução de forma diferente, é aí que se resolve.

---

## Resumo: quem faz o quê

| Passo | Rota | O que o Hub faz sozinho | O que a **transportadora nova** implementa |
|---|---|---|---|
| 0 | `PUT /transportadoras/{t}` (ou `transportadora:configurar`) | valida e guarda criptografado, por cliente | `perguntas()` e `regras()` |
| 1 | `GET /enderecos/{cep}` | consulta o ViaCEP | nada |
| 2 | `GET .../pontos-retirada` | junta quem oferece | `PontosDeRetirada` (opcional) |
| 3 | `POST .../cotacoes` | envia tudo em paralelo, ordena, salva | `pedidosDeCotacao()` e `lerCotacao()` |
| 4 | `POST .../remessas` | idempotência, reserva, status | `criarRemessa()` |
| 5 | `GET .../etiqueta` | guarda e serve o PDF | `etiqueta()` |
| 6 | `POST .../despachar` | muda o status | nada |
| 7 | `POST .../cancelar` | regras de status | `cancelarRemessa()` |
| 8 | `POST .../devolucoes` | inverte as partes, reaproveita o passo 4 | nada novo (usa `criarRemessa` com `tipo = devolucao`) |

**Ou seja: a transportadora nova implementa 8 pequenos métodos (+ 1 opcional) e mais nada no sistema muda.**

**Listagens:** `GET /remessas` e `GET /devolucoes` ([`RemessaController::index`](../app/Http/Controllers/RemessaController.php), [`DevolucaoController::index`](../app/Http/Controllers/DevolucaoController.php))
mostram os envios (ou devoluções) **do cliente**, mais novos primeiro, 50 por página, com o filtro opcional `?status=criada`. A consulta parte do cliente, como todas as outras.
Não envolvem a transportadora, então uma transportadora nova não implementa nada para elas.

---

# Parte 2: implementar uma transportadora nova

## Visão geral e checklist

Você **não** mexe em rotas, controllers, services, requests nem no Postman. Você cria **uma classe**, registra o nome e acrescenta o simulador e os testes.

- [ ] **A.** Estudar a documentação da transportadora e anotar o que ela exige
- [ ] **B.** Criar a classe (copiando o modelo abaixo) e implementar `regras()` e `perguntas()`
- [ ] **C.** Registrar em `Transportadoras::LISTA` e colocar as URLs em `config/transportadoras.php`
- [ ] **D.** Implementar `pedidosDeCotacao()` e `lerCotacao()`
- [ ] **E.** Implementar `criarRemessa()`, `cancelarRemessa()` e `etiqueta()`
- [ ] **F.** (Se houver) implementar `PontosDeRetirada`
- [ ] **G.** Simulador: fixtures + rotas em `SimuladorHttp`
- [ ] **H.** Testes e documentação

## Etapa A: estude a documentação da transportadora

Responda **antes de escrever código** (anote; dúvidas viram "premissas" em `docs/premissas-das-transportadoras.md`):
- Como autentica? (token fixo? login que gera token com validade?) Se tiver validade, vamos guardar no cache como os Correios fazem ([`token()`](../app/Transportadoras/Correios.php#L462)).
- Quais são as chamadas de: **cotação**, **criar remessa**, **cancelar**, **etiqueta**, **pontos de retirada**?
- Cotação é uma chamada ou várias? Aceita lote? Qual o limite de itens por chamada?
- Unidades: peso em kg ou g? valor em reais (decimal) ou centavos? medidas em cm?
- Como a transportadora **diz que algo deu errado**? (HTTP 4xx com mensagem? HTTP 200 com campo de erro?)
- Como devolve o **código de rastreio** e a **etiqueta** (PDF direto, base64, outro endpoint)?
- Existe ambiente de testes (homologação)?

## Etapa B: a classe, `regras()` e `perguntas()`

Crie `app/Transportadoras/NomeDaTransportadora.php` (copie o [modelo](#modelo-de-classe-para-copiar)).

- **`regras()`**: as regras de validação, no mesmo estilo dos Form Requests, do que o cadastro precisa. Campos em `credenciais.*` são **secretos** (ficam criptografados); em `configuracao.*` não.
  Veja [`Jadlog::regras()`](../app/Transportadoras/Jadlog.php#L19) e [`Correios::regras()`](../app/Transportadoras/Correios.php#L25).
- **`perguntas()`**: as perguntas que o comando `transportadora:configurar` faz, **na ordem**, para preencher esses campos.
  Cada pergunta diz onde o valor entra (`campo`), o texto (`rotulo`) e se é `secreto`. Veja [`Jadlog::perguntas()`](../app/Transportadoras/Jadlog.php#L41).
- Para uma lista de serviços ("código:nome,código:nome") use `'tipo' => 'servicos'`; o comando converte sozinho.
- **Teste na prática:** rode `docker/scripts/artisan.sh transportadora:configurar nova` e responda as perguntas.

## Etapa C: registrar e configurar

1. [`Transportadoras::LISTA`](../app/Transportadoras/Transportadoras.php#L16): acrescente `'nova' => NomeDaTransportadora::class`.
   **Esse é o único lugar do sistema que "conhece" as transportadoras.** Depois disso, `transportadora:configurar nova`, a validação das rotas e a cotação já a reconhecem.
2. [`config/transportadoras.php`](../config/transportadoras.php): acrescente a seção `'nova' => ['url' => env('NOVA_URL', 'https://...')]`.
   **Nunca** coloque URL em outro lugar e **nunca** aceite URL vinda do usuário (evita que alguém faça o sistema chamar um endereço malicioso).

## Etapa D: cotação

Implemente os dois métodos (leia como a [Jadlog](../app/Transportadoras/Jadlog.php#L17) faz).

**`pedidosDeCotacao(array $dados): array`** devolve uma **lista de chamadas**, sem enviar nada:
```php
return [[
    'chave'   => 'cotacao',               // nome único dentro desta transportadora
    'metodo'  => 'POST',
    'url'     => config('transportadoras.nova.url').'/cotar',
    'headers' => ['Authorization' => '...'],
    'corpo'   => [ ... ],                 // o JSON que a transportadora espera (ou null)
    // 'query' => [...],  'basic' => [usuario, senha]   (opcionais)
]];
```
Use `$dados['cep_origem']`, `$dados['cep_destino']`, `$dados['valor_declarado_centavos']`, `$dados['volumes']` (formato na seção [formatos](#os-formatos-de-dados)).

**`lerCotacao(array $dados, array $respostas): array`** recebe `[chave => Response|null]` (a `chave` que você definiu) e devolve:
```php
[['servico_codigo' => '10', 'servico_nome' => 'Expresso', 'valor_centavos' => 1990, 'prazo_dias' => 3], ...]
```
Passos recomendados dentro dele (copie a estrutura da Jadlog):
1. Para cada resposta, chame `TransportadoraIndisponivel::motivoDaResposta($resposta)`: se devolver um motivo (sem resposta, 401/403, 5xx), anote e continue.
2. Leia o JSON. Se não for JSON → motivo `resposta_invalida`.
3. Converta valores para **centavos** com [`Dinheiro`](../app/Support/Dinheiro.php) (`deReais`, `deTextoBrasileiro`).
4. Se **nenhuma** resposta serviu, `throw new TransportadoraIndisponivel($motivo)`. Se a transportadora respondeu "não atendo este CEP" (erro de negócio), lance `new TransportadoraIndisponivel('sem_cobertura')`.
5. Devolva a lista de opções.

> `pedidosDeCotacao` e `lerCotacao` **não guardam estado** entre si (a mesma classe pode ser criada de novo entre as duas). Se precisar relacionar pedido e resposta, use a `chave`, ou recalcule a partir de `$dados` (os [Correios](../app/Transportadoras/Correios.php#L23) fazem isso em `entradasDePreco`).

## Etapa E: remessa, cancelamento e etiqueta

**`criarRemessa(array $remessa): array`**: monte o JSON da transportadora a partir dos dados normalizados e envie com `$this->http->enviar(...)` (nunca com `Http::` direto):
```php
$resposta = $this->http->enviar($this->clienteId, 'nova', 'criar_remessa', [
    'metodo' => 'POST', 'url' => ..., 'headers' => ..., 'corpo' => [...],
]);
```
(o 2º parâmetro é o código da transportadora e o 3º é o nome da operação; ambos aparecem no histórico de chamadas.)

Devolva:
```php
return [
    'id_externo'      => 'ABC123',                 // para cancelar/etiqueta depois
    'codigo_rastreio' => 'XY123456789BR',
    'volumes'         => [ ['id_externo' => 'ABC123', 'codigo_rastreio' => 'XY123456789BR'], ... ],   // mesma ordem de $remessa['volumes']
    'etiqueta_pdf'    => $pdfOuNull,
];
```
Se a transportadora usa **um código por pedido**, repita o mesmo em todos os volumes (Jadlog). Se usa **um por volume**, crie um por volume (Correios) e **cancele os já criados se um falhar**.

**`cancelarRemessa(array $remessa): void`**: recebe `id_externo` e `volumes[]`. Se a transportadora disser "já está cancelado", **retorne normalmente** (não é erro).

**`etiqueta(array $remessa): ?string`**: devolve o conteúdo do PDF, ou `null` se ela só entrega na criação.
Valide que é PDF de verdade antes de devolver (`str_starts_with($pdf, '%PDF')`).

## Etapa F: pontos de retirada (opcional)

Se a transportadora tiver, a classe passa a `implements Transportadora, PontosDeRetirada` e implementa `pontosDeRetirada(string $cep, ?string $tipos = null): array`
(formato do retorno no docblock da [interface](../app/Transportadoras/PontosDeRetirada.php)). Não precisa registrar nada: o [`ConsultasService`](../app/Services/ConsultasService.php#L14) a descobre sozinho.

## Etapa G: o simulador (modo mock)

Sem isso, nenhum teste nem o desenvolvimento funcionam (não temos contas reais). O [`SimuladorHttp`](../app/Transportadoras/Mock/SimuladorHttp.php#L19) intercepta as chamadas HTTP e responde com arquivos.
1. Crie `app/Transportadoras/Mock/fixtures/nova/` com **cópias dos exemplos da documentação** da transportadora (sucesso e erro), no formato exato.
2. Em [`SimuladorHttp::responder`](../app/Transportadoras/Mock/SimuladorHttp.php#L38), acrescente uma linha no `match` para cada URL da transportadora nova e um método privado que devolve a fixture
   (copie [`jadlogFrete`](../app/Transportadoras/Mock/SimuladorHttp.php#L63) como modelo). Use `self::cenario($cep)` e `self::falhaComum(...)` para os cenários de timeout, erro 500 etc. por CEP.
3. Documente em [Mock/README.md](../app/Transportadoras/Mock/README.md) o que for específico.

## Etapa H: testes e documentação

1. Copie [JadlogTest.php](../tests/Feature/Transportadoras/JadlogTest.php) para `NovaTest.php` e adapte. Teste, no mínimo:
   - o **pedido de cotação** que a classe monta (URL, headers, corpo);
   - a leitura da cotação em **sucesso** (valores em centavos);
   - **cada falha** (sem resposta, credencial recusada, erro 500, resposta quebrada, sem cobertura);
   - criar, cancelar (inclusive "já cancelado") e etiqueta.
2. No `TestCase`, crie um helper `configurarNova($cliente)` (copie `configurarJadlog`).
3. Rode **`docker/scripts/test.sh`**. Os testes antigos têm de continuar passando.
4. Acrescente a transportadora no [README](../README.md) (seção 3.1 e tabela de erros, se houver código novo) e anote dúvidas em [premissas](premissas-das-transportadoras.md).
5. Rode a [collection do Postman](postman/frete-hub.postman_collection.json) (precisa de `up.sh` antes, para a imagem pegar o código novo).

---

## Modelo de classe para copiar

```php
<?php

declare(strict_types=1);

namespace App\Transportadoras;

use App\Exceptions\ErroDeNegocio;
use App\Models\CredencialTransportadora;
use App\Support\Dinheiro;

class NovaTransportadora implements Transportadora
{
    public static function regras(): array
    {
        return [
            'credenciais' => ['required', 'array'],
            'credenciais.token' => ['required', 'string', 'max:2000'],   // secreto

            'configuracao' => ['required', 'array'],
            'configuracao.servicos' => ['required', 'array', 'min:1'],
            'configuracao.servicos.*.codigo' => ['required', 'string'],
            'configuracao.servicos.*.nome' => ['required', 'string', 'max:60'],
        ];
    }

    public static function perguntas(): array
    {
        return [
            ['campo' => 'credenciais.token', 'rotulo' => 'Token da NovaTransportadora', 'secreto' => true],
            ['campo' => 'configuracao.servicos', 'rotulo' => 'Servicos (codigo:nome,codigo:nome)', 'tipo' => 'servicos'],
        ];
    }

    // O container do Laravel NAO cria esta classe (quem cria e Transportadoras::criar). Mantenha este construtor igual ao da Jadlog.
    public function __construct(
        private readonly CredencialTransportadora $credencial,
        private readonly ClienteHttp $http,
        private readonly ?string $clienteId = null,
    ) {
    }

    public function codigo(): string
    {
        return 'nova';      // o MESMO nome usado em Transportadoras::LISTA
    }

    public function pedidosDeCotacao(array $dados): array
    {
        return [[
            'chave' => 'cotacao',
            'metodo' => 'POST',
            'url' => config('transportadoras.nova.url').'/cotar',
            'headers' => $this->cabecalhos(),
            'corpo' => [
                // TODO: montar o corpo que a documentacao pede, usando $dados
            ],
        ]];
    }

    public function lerCotacao(array $dados, array $respostas): array
    {
        $resposta = $respostas['cotacao'] ?? null;

        // 1. problema da transportadora? (sem resposta, 401/403, 5xx)
        if ($motivo = TransportadoraIndisponivel::motivoDaResposta($resposta)) {
            throw new TransportadoraIndisponivel($motivo);
        }

        // 2. JSON valido?
        $json = $resposta->json();
        if (! is_array($json)) {
            throw new TransportadoraIndisponivel('resposta_invalida');
        }

        // 3. TODO: erro de negocio da transportadora (ex.: CEP nao atendido)? throw new TransportadoraIndisponivel('sem_cobertura');

        // 4. TODO: converter cada item para o nosso formato (valor em CENTAVOS)
        $opcoes = [];
        foreach ($json['servicos'] ?? [] as $item) {
            $opcoes[] = [
                'servico_codigo' => (string) $item['codigo'],
                'servico_nome' => (string) $item['nome'],
                'valor_centavos' => Dinheiro::deReais($item['preco']),
                'prazo_dias' => (int) $item['prazo'],
            ];
        }

        return $opcoes;
    }

    public function criarRemessa(array $remessa): array
    {
        $resposta = $this->http->enviar($this->clienteId, 'nova', 'criar_remessa', [
            'metodo' => 'POST',
            'url' => config('transportadoras.nova.url').'/pedidos',
            'headers' => $this->cabecalhos(),
            'corpo' => [
                // TODO: montar a partir de $remessa (pessoas, volumes, servico_codigo...)
            ],
        ]);

        if ($motivo = TransportadoraIndisponivel::motivoDaResposta($resposta)) {
            throw new TransportadoraIndisponivel($motivo);
        }

        $json = $resposta->json();

        // Recusa de negocio (dados invalidos, CEP sem cobertura...): erro 422 para o cliente, SEM repetir o texto da transportadora.
        if (isset($json['erro'])) {
            throw new ErroDeNegocio('remessa_recusada', 'A transportadora recusou a remessa.', 422, ['motivo' => 'recusada']);
        }
        if (! $resposta->successful() || empty($json['codigo'])) {
            throw new TransportadoraIndisponivel('resposta_invalida');
        }

        $codigo = (string) $json['codigo'];

        return [
            'id_externo' => $codigo,
            'codigo_rastreio' => $codigo,
            'volumes' => array_map(fn () => ['id_externo' => $codigo, 'codigo_rastreio' => $codigo], $remessa['volumes']),
            'etiqueta_pdf' => null,   // ou o PDF, se a transportadora devolver agora
        ];
    }

    public function cancelarRemessa(array $remessa): void
    {
        // TODO: chamar o cancelamento. "Ja esta cancelado" NAO e erro: retorne normalmente.
    }

    public function etiqueta(array $remessa): ?string
    {
        return null;   // TODO: buscar o PDF, ou null se ela so entrega na criacao
    }

    private function cabecalhos(): array
    {
        return ['Authorization' => 'Bearer '.($this->credencial->credenciais['token'] ?? '')];
    }
}
```

---

## Os formatos de dados

**Entrada da cotação (`$dados`):**
```php
['cep_origem' => '01310100', 'cep_destino' => '35432065', 'valor_declarado_centavos' => 15000,
 'volumes' => [['peso_gramas' => 1200, 'altura_cm' => 10, 'largura_cm' => 20, 'comprimento_cm' => 30]]]
```

**Entrada de `criarRemessa` (`$remessa`):**
```php
['id' => 'uuid', 'tipo' => 'envio' /* ou 'devolucao' */, 'servico_codigo' => '03220',
 'valor_declarado_centavos' => 15000, 'numero_pedido' => 'PED-1' /* ou null */, 'conteudo' => 'MERCADORIA',
 'ponto_retirada_id' => null, 'documento_fiscal' => null /* ou ['tipo','chave','numero','serie','cfop'] */,
 'remetente'    => <pessoa>,      // quem DESPACHA nesta remessa (na devolucao, e quem recebeu o pedido)
 'destinatario' => <pessoa>,      // quem RECEBE
 'volumes' => [['peso_gramas' => ..., 'altura_cm' => ..., 'largura_cm' => ..., 'comprimento_cm' => ...]]]

// <pessoa>
['nome' => 'Maria da Silva', 'documento' => '52998224725' /* so numeros */, 'telefone' => '31987654321' /* 10 ou 11 digitos */,
 'email' => '...' /* ou null */,
 'endereco' => ['cep' => '35432065', 'logradouro' => '...', 'numero' => '10', 'complemento' => null, 'bairro' => '...', 'cidade' => '...', 'uf' => 'MG']]
```
Já vem **validado e normalizado** (só números, UF maiúscula): não precisa revalidar.
Quem **paga** o frete não vem aí: é o titular do contrato, e fica na configuração da transportadora (`$this->credencial->configuracao`).

**Entrada de `cancelarRemessa` e `etiqueta`:** `['id_externo' => '...', 'volumes' => [['id_externo' => '...', 'codigo_rastreio' => '...', ...]]]`.

**Unidades do sistema (converta na sua classe):** dinheiro em **centavos (inteiro)**; peso em **gramas**; medidas em **cm**; prazo em **dias**.

## Que erro lançar em cada situação

| Situação | O que fazer | O cliente vê |
|---|---|---|
| Timeout, sem resposta | `TransportadoraIndisponivel('sem_resposta')` (use `motivoDaResposta`) | cotação: em `falhas`; remessa: 502 |
| Token/senha recusados (401/403) | `TransportadoraIndisponivel('credencial_invalida')` | idem |
| Erro 5xx da transportadora | `TransportadoraIndisponivel('erro_na_transportadora')` | idem |
| Resposta que não é o formato esperado | `TransportadoraIndisponivel('resposta_invalida')` | idem |
| Não atende este CEP (na cotação) | `TransportadoraIndisponivel('sem_cobertura')` | em `falhas` |
| Recusou os dados na criação | `ErroDeNegocio('remessa_recusada', 'A transportadora recusou a remessa.', 422, ['motivo' => '...'])` | 422 |
| Recusou o cancelamento | `ErroDeNegocio('cancelamento_recusado', '...', 422)` | 422 |
| "Já está cancelado" | **não lance nada** | tudo certo |

**Regra:** o texto de erro da transportadora **nunca** vai para a resposta. Ele já fica no histórico de chamadas (com dados mascarados) para a TI investigar pelo `request_id`.

## Erros que já cometemos (evite)

- **Citar o nome da transportadora fora de `app/Transportadoras/`.** Havia um serviço que chamava a Jadlog direto. Hoje um teste (`GUARDA`) falha se isso acontecer.
- **Usar `float` para dinheiro.** Sempre `Dinheiro::deReais(...)` / `deTextoBrasileiro(...)`.
- **Chamar `Http::` direto.** Sempre `$this->http->enviar(...)` (é ele que aplica timeout, TLS, histórico mascarado).
- **Esquecer de tratar `null` em `lerCotacao`.** `null` significa "não houve resposta" (timeout): é o caso mais comum de falha.
- **Devolver a mensagem da transportadora ao cliente.** Ela pode ter dado pessoal, SQL ou detalhes internos.
- **Pôr URL em código ou aceitar URL de entrada.** Só em `config/transportadoras.php`.
- **Guardar estado entre `pedidosDeCotacao` e `lerCotacao`** (propriedade na classe): a mesma instância não é garantida. Use a `chave` ou recalcule.
- **Tratar "já cancelado" como erro.** Cancelar de novo precisa dar certo.

## Como saber que terminou

- [ ] `docker/scripts/test.sh` verde (inclusive o teste GUARDA)
- [ ] `docker/scripts/artisan.sh transportadora:configurar nova` funciona e salva
- [ ] No Postman (com `up.sh` rodado antes): cotação mostra opções da nova transportadora; criar remessa, etiqueta, despachar, cancelar funcionam
- [ ] Cenários de falha do simulador (`22222222`, `33333333`, `44444444`, `55555555`, `66666666`) dão o resultado esperado para a nova transportadora
- [ ] Dúvidas anotadas em `docs/premissas-das-transportadoras.md`
- [ ] README atualizado onde a transportadora aparece
