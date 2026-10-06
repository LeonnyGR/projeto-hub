# Frete Hub

API de frete para o B2C. Com ela, o B2C:

- cadastra os **sellers** (lojistas);
- consulta o endereço de um CEP e os pontos de retirada;
- cota o frete na **Jadlog** e nos **Correios** ao mesmo tempo, **em nome de um seller**;
- cria a remessa, imprime a etiqueta, cancela;
- cria devoluções.

> **Ainda não está pronto:** o rastreio (`GET .../remessas/{id}/rastreio`). É a próxima etapa.

---

## Como funciona (leia primeiro)

```
Cliente final ──► B2C (site/app) ──► seu backend ──► Frete Hub ──► Jadlog / Correios
                  escolhe o seller       chave de API     /sellers/{id}/...
```

- **Quem chama a API é o backend do B2C**, com **uma única chave de API** (header `Authorization`).
  A chave **nunca** pode ir para o navegador ou para o app do cliente final.
- **O seller não tem chave nem contrato.** Quando o cliente escolhe o seller no B2C, o backend do B2C chama a API
  informando o seller **na URL**: `/api/v1/sellers/{seller_id}/cotacoes`.
- **O contrato com as transportadoras é do B2C (Bartofil)**: token da Jadlog, cartão de postagem dos Correios.
  A TI cadastra esses dados **uma vez, por comando** (nunca por API) e eles valem para todos os sellers. O que muda de um seller para outro é só o **remetente** da remessa
  (nome, documento e endereço do lojista). Quem **paga o frete** é sempre o titular do contrato.
- Para um novo lojista entrar, basta o B2C chamar `POST /sellers`. Nada para configurar no servidor.

---

## Índice

1. [Como subir o sistema](#1-como-subir-o-sistema)
2. [Criar a chave de API do B2C](#2-criar-a-chave-de-api-do-b2c)
3. [Cadastrar as transportadoras e os sellers](#3-cadastrar-as-transportadoras-e-os-sellers)
4. [Testar pelo Postman](#4-testar-pelo-postman)
5. [Usar a API (curl)](#5-usar-a-api-curl)
6. [Modo simulado (mock)](#6-modo-simulado-mock)
7. [Erros da API](#7-erros-da-api)
8. [Rodar os testes automáticos](#8-rodar-os-testes-automáticos)
9. [Como o código é organizado](#9-como-o-código-é-organizado)
10. [Como adicionar uma transportadora nova](#10-como-adicionar-uma-transportadora-nova)
11. [Parar e limpar tudo](#11-parar-e-limpar-tudo)
12. [Antes de ir para produção](#12-antes-de-ir-para-produção)
13. [Outros documentos](#13-outros-documentos)

---

## 1. Como subir o sistema

**O que você precisa ter instalado:** Docker e `openssl`. Não é preciso instalar PHP.

```bash
# 1. Sobe tudo: banco (Postgres), Redis e a API. O "--scheduler" liga também o agendador.
docker/scripts/up.sh --scheduler

# 2. Cria as tabelas do banco
docker/scripts/migrate.sh

# 3. Confere se está no ar
curl http://127.0.0.1:8090/up
# resposta: {"status":"ok"}
```

Na primeira vez o `up.sh` cria o arquivo `.env.docker` com senhas aleatórias. **Não coloque esse arquivo no git.**

**A API roda na porta `8090`.** (A 8080 já é usada por outro app neste servidor.)

**Para acessar de outra máquina** (por exemplo, pelo Postman em `http://devb:8090`):
no `.env.docker`, troque `APP_HOST_BIND=127.0.0.1` por `APP_HOST_BIND=0.0.0.0` e rode `docker/scripts/up.sh --scheduler` de novo.
Se der "connection refused" de outra máquina, a porta TCP 8090 pode estar bloqueada no firewall do servidor.

O banco e o Redis **não ficam abertos** para fora do Docker. Para olhar o banco do seu computador (só em desenvolvimento),
suba com `docker/scripts/up.sh --expose-postgres`.

Outros scripts úteis:

| Script | Para que serve |
|---|---|
| `docker/scripts/logs.sh` | Ver os logs da API (`logs.sh postgres`, `logs.sh redis`, `-f` acompanha ao vivo) |
| `docker/scripts/artisan.sh <comando>` | Rodar um comando artisan (criar a chave do B2C etc.) |
| `docker/scripts/test.sh` | Rodar os testes automáticos |
| `docker/scripts/down.sh` | Parar tudo (os dados continuam salvos) |

---

## 2. Criar a chave de API do B2C

É o **único** passo que precisa de comando no servidor (é preciso ter uma chave para chamar a API pela primeira vez).

```bash
docker/scripts/artisan.sh cliente:create "B2C"
```

A saída mostra o **id do cliente** e a **chave**:

```
Cliente criado: 01a11213-3c84-712d-850b-49487e26690a
Guarde a chave agora. Ela nao sera mostrada de novo:
fk_880ea98419e4.1OhY7etp5YBZSNU5D9QcAxBkvCRXvlxweC1-865Kqx8
```

- **A chave aparece uma única vez.** O banco guarda só um resumo (hash) dela. Se perder, crie outra (abaixo).
- Guarde a chave no cofre de segredos do B2C. Ela vai em toda chamada, no header: `Authorization: Bearer fk_...`.

Outros comandos:

```bash
docker/scripts/artisan.sh cliente:list                       # lista os clientes e quantas chaves ativas cada um tem
docker/scripts/artisan.sh cliente:rotate-key <id-do-cliente> # cria uma chave nova; a antiga vale mais 24h (para dar tempo de trocar)
docker/scripts/artisan.sh cliente:rotate-key <id> --revoke-old   # a antiga deixa de valer na hora
docker/scripts/artisan.sh cliente:disable <id-do-cliente>    # bloqueia todas as chaves (--enable libera de novo)
```

---

## 3. Cadastrar as transportadoras e os sellers

### 3.1 Transportadoras: pela TI, por comando (uma vez só)

O contrato com a Jadlog e com os Correios é feito **com as transportadoras** (comercial, Meu Correios, catálogo dos Correios).
Aqui só guardamos uma cópia **criptografada** dos dados que elas entregaram. Isso é feito **por comando, não por API**,
de propósito: são dados sensíveis, que mudam raramente, e a chave usada todos os dias pelo B2C não deve poder trocá-los.

```bash
docker/scripts/artisan.sh transportadora:configurar jadlog
docker/scripts/artisan.sh transportadora:configurar correios
```

O comando faz as perguntas. O que você digita em campo secreto (token, senha, cartão) **não aparece na tela** e não fica no histórico do terminal.
Se houver só um cliente da API, o comando o escolhe sozinho; com mais de um, informe o id depois do nome da transportadora (`cliente:list` mostra os ids).
Se os dados já existirem, ele pergunta antes de substituir. Se algum dado for inválido (CNPJ errado, UF inexistente...), ele mostra o que está errado e **não salva nada**.

**Jadlog** pergunta:

| Pergunta | O que responder |
|---|---|
| Token da Jadlog | O token que a Jadlog entregou (secreto) |
| Tomador: nome, CNPJ, telefone, e-mail, endereço | **Quem paga o frete** (titular do contrato, a Bartofil). A Jadlog exige os dados completos |
| Código do cliente | Código do cliente na Jadlog |
| Conta corrente / Contrato / Unidade | Se tiver. Se não, aperte Enter |
| Tipo de coleta | `K` = a Jadlog coleta no seller (padrão), `S` = o seller leva até a Jadlog |
| Modalidades contratadas | Formato `código:nome`, separadas por vírgula. Ex.: `3:Package,0:Expresso` |

**Correios** pergunta: usuário do Meu Correios, senha de acesso à API (secreta), número do cartão de postagem (secreto),
contrato (opcional) e serviços contratados (ex.: `03220:SEDEX,04669:PAC`).

Outros comandos:

```bash
docker/scripts/artisan.sh transportadora:ativar jadlog --desativar   # tira a Jadlog das cotações de todos os sellers, sem apagar os dados
docker/scripts/artisan.sh transportadora:ativar jadlog               # volta a usar
```

Pela API só dá para **consultar** o que está cadastrado (sem segredos):

```bash
URL=http://127.0.0.1:8090/api/v1
KEY=fk_sua_chave_aqui
curl -s $URL/transportadoras -H "Authorization: Bearer $KEY"
```

> Em **modo simulado** (padrão em desenvolvimento) pode usar dados de mentira: nenhuma transportadora real é chamada.

### 3.2 Sellers: pelo B2C, por API

Cada lojista entra por `POST /sellers`. Não precisa de chave nem de cadastro de transportadora: o seller já nasce usando as credenciais do B2C.

```bash
curl -s -X POST $URL/sellers -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"nome": "Loja do João", "codigo_externo": "b2c-123"}'
```

```json
{ "data": { "id": "01a11213-4764-722b-a802-4b3be85140cd", "nome": "Loja do João", "codigo_externo": "b2c-123", "ativo": true } }
```

- **Guarde o `id`**: é o `seller_id` que vai na URL de todas as outras chamadas.
- `codigo_externo` é o id desse lojista no B2C. É opcional, mas recomendado: se a chamada for repetida, a API responde
  **409 `seller_ja_existe`** com o `seller_id` que já existe, em vez de criar um segundo cadastro.
- **O seller não recebe chave nenhuma**, e **não precisa cadastrar transportadora**: já nasce usando as credenciais do B2C.

Outras chamadas de seller: `GET /sellers` (lista, com paginação), `GET /sellers/{id}`,
`PATCH /sellers/{id}` (muda `nome` ou `ativo`).
Seller com `ativo: false` não cota nem cria remessa (os dados continuam consultáveis).
Um cliente da API só enxerga os sellers que ele mesmo cadastrou.

---

## 4. Testar pelo Postman

1. No Postman: **Import** → **Raw text** → cole o conteúdo de
   [`docs/postman/frete-hub.postman_collection.json`](docs/postman/frete-hub.postman_collection.json) → **Import**.
2. Abra a collection **Frete Hub** → aba **Variables** e preencha:
   - `base_url`: o endereço da API (já vem `http://devb:8090`);
   - `api_key`: a chave do B2C (passo 2).
3. Antes, a TI precisa ter cadastrado as transportadoras (seção 3.1). Rode as pastas **na ordem**: `1 - Transportadoras do B2C` (só confere), `2 - Sellers`, `3 - Consultas`, `4 - Cotações`,
   `5 - Remessas`, `6 - Devoluções`. As variáveis `seller_id`, `cotacao_id`, `remessa_id`... se preenchem sozinhas.
   Para testar com outro seller, rode **Criar seller** de novo (ele troca o `seller_id`).

Observações:

- **Criar remessa** gera uma `Idempotency-Key` nova a cada envio. **"Criar remessa (repetir a mesma chamada)"** reenvia a mesma chave
  e mostra que a API devolve a remessa que já existe, sem criar outra.
- **Devoluções** só funcionam para remessa **entregue**. Enquanto o rastreio não está pronto, o resultado esperado é o erro
  `422 remessa_nao_entregue`.
- A pasta **7 - Cenários do simulador** testa falhas (timeout, erro 500...). Veja a tabela na seção 6.

---

## 5. Usar a API (curl)

Com `URL` e `KEY` definidos como na seção 3, e `SELLER` com o id do seller (`SELLER=01a11213-...`). Todas as rotas abaixo (menos CEP) começam com `$URL/sellers/$SELLER`.

### Consultar CEP e pontos de retirada

```bash
curl -s $URL/enderecos/01001000 -H "Authorization: Bearer $KEY"                     # não depende de seller
curl -s "$URL/sellers/$SELLER/pontos-retirada?cep=02031100&tipos=LOCKER,JADLOG" -H "Authorization: Bearer $KEY"
```

O CEP é consultado no **ViaCEP de verdade**, mesmo em modo simulado (e o resultado é guardado por 7 dias).

Os pontos de retirada vêm de **todas as transportadoras ativas que oferecem isso** (hoje, só a Jadlog); cada ponto diz de qual transportadora é
(`"transportadora": "jadlog"`). Se uma transportadora não responder, ela aparece em `falhas` e as outras continuam.
Filtros opcionais: `transportadora=jadlog` (só uma) e `tipos=LOCKER,PUBLICO` (cada transportadora usa os tipos que conhece).
Se nenhuma transportadora ativa oferecer pontos de retirada, a resposta é 422 `sem_pontos_de_retirada`.

### Cotar o frete

```bash
curl -s -X POST $URL/sellers/$SELLER/cotacoes -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" -d '{
  "cep_origem": "01310100",
  "cep_destino": "20040020",
  "valor_declarado_centavos": 15000,
  "volumes": [{"peso_gramas": 1200, "altura_cm": 10, "largura_cm": 20, "comprimento_cm": 30}]
}'
```

A resposta traz as opções **da mais barata para a mais cara**, com o valor em **centavos**:

```json
{ "data": {
    "id": "01a11181-31d0-7338-ac3b-9d2452b7976c",
    "expira_em": "2026-10-06T14:12:22+00:00",
    "opcoes": [
      { "transportadora": "jadlog", "servico_codigo": "0", "servico_nome": "Expresso", "valor_centavos": 860, "prazo_dias": 3 },
      { "transportadora": "correios", "servico_codigo": "03220", "servico_nome": "SEDEX", "valor_centavos": 1240, "prazo_dias": 3 }
    ],
    "falhas": []
} }
```

- A cotação **vale 15 minutos**.
- Se uma transportadora não responder, ela some de `opcoes` e aparece em `falhas` com o motivo
  (`sem_resposta`, `credencial_invalida`, `erro_na_transportadora`, `resposta_invalida` ou `sem_cobertura`). As outras continuam normalmente.

### Criar a remessa

Use o `id` da cotação e uma das opções dela. O header **`Idempotency-Key` é obrigatório**: invente um valor único por remessa
(o id do pedido da loja serve). Se a chamada for repetida com a mesma chave, a API devolve a remessa que já existe.

```bash
curl -s -X POST $URL/sellers/$SELLER/remessas -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -H "Idempotency-Key: pedido-1001" -d '{
  "cotacao_id": "ID-DA-COTACAO",
  "transportadora": "jadlog",
  "servico_codigo": "3",
  "numero_pedido": "PED-1001",
  "remetente": {
    "nome": "Loja Exemplo Ltda", "documento": "11222333000181", "telefone": "1133334444", "email": "contato@loja.com.br",
    "endereco": {"cep": "01310100", "logradouro": "Avenida Paulista", "numero": "1000", "bairro": "Bela Vista", "cidade": "Sao Paulo", "uf": "SP"}
  },
  "destinatario": {
    "nome": "Maria da Silva", "documento": "52998224725", "telefone": "21987654321",
    "endereco": {"cep": "20040020", "logradouro": "Rua da Assembleia", "numero": "10", "bairro": "Centro", "cidade": "Rio de Janeiro", "uf": "RJ"}
  }
}'
```

Regras importantes:

- Os **CEPs** de remetente e destinatário precisam ser os mesmos da cotação. O preço foi calculado para eles.
- **Documento** (CPF ou CNPJ) precisa ser válido. Telefone com DDD (10 ou 11 dígitos). Máscaras (`11.222.333/0001-81`) são aceitas.
- Nota fiscal é opcional (`documento_fiscal`). Sem ela, a Jadlog recebe uma declaração de conteúdo.
- A resposta traz `codigo_rastreio` e, em `volumes`, **um item por volume**. Na Jadlog todos os volumes têm o mesmo código;
  nos Correios cada volume tem o seu.

### Etiqueta, despachar e cancelar

```bash
R=$URL/sellers/$SELLER/remessas/ID-DA-REMESSA
curl -s $R/etiqueta -H "Authorization: Bearer $KEY" -o etiqueta.pdf        # PDF para imprimir
curl -s -X POST $R/despachar -H "Authorization: Bearer $KEY"               # o seller entregou o pacote
curl -s -X POST $R/cancelar  -H "Authorization: Bearer $KEY"               # só se criada ou despachada
curl -s $R -H "Authorization: Bearer $KEY"                                 # ver a remessa
```

Status da remessa: `criada` → `despachada` → `em_transito` → `saiu_para_entrega` → `entregue` (ou `cancelada`).
Para entender cada um, veja o [glossário](docs/glossario.md).

### Devolução

Só para remessa **entregue**. A devolução é uma nova remessa no sentido contrário (do cliente para a loja), na mesma transportadora.

```bash
D=$URL/sellers/$SELLER/devolucoes
curl -s -X POST $D -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -H "Idempotency-Key: devolucao-pedido-1001" -d '{"remessa_id": "ID-DA-REMESSA", "motivo": "Produto com defeito"}'

curl -s $D/ID           -H "Authorization: Bearer $KEY"
curl -s $D/ID/etiqueta  -H "Authorization: Bearer $KEY" -o etiqueta-devolucao.pdf
```

---

## 6. Modo simulado (mock)

Serve para desenvolver e testar **sem conta real** na Jadlog nem nos Correios. É o padrão em desenvolvimento
(`TRANSPORTADORAS_MODO=mock` no `.env.docker`).

- O código da Jadlog e dos Correios roda de verdade; só a resposta da internet é simulada, a partir de arquivos copiados dos exemplos dos PDFs.
- **O modo simulado não sobe com `APP_ENV=production`** (de propósito, para nunca ir para produção por engano).
- Para falar com as transportadoras de verdade: `TRANSPORTADORAS_MODO=real` (veja a seção 12 antes).

O **CEP de destino** escolhe o que o simulador responde:

| CEP de destino | O que acontece |
|---|---|
| qualquer outro | Sucesso |
| `22222222` | Sem cobertura (CEP não encontrado na consulta de CEP) |
| `33333333` | Timeout (transportadora não responde) |
| `44444444` | Erro 500 da transportadora |
| `55555555` | Resposta quebrada |
| `66666666` | Credencial recusada |

Mais detalhes (cancelamento, etiqueta, valores): [`app/Transportadoras/Mock/README.md`](app/Transportadoras/Mock/README.md).

---

## 7. Erros da API

Todo erro tem o mesmo formato:

```json
{ "erro": { "codigo": "cotacao_expirada", "mensagem": "A cotacao expirou. Faca uma nova cotacao." }, "request_id": "9f2c..." }
```

O `request_id` também vem no header `X-Request-Id` e aparece nos logs. Se alguém reclamar de um erro, peça esse número.

| Status | Quando acontece | Códigos mais comuns |
|---|---|---|
| 401 | Chave ausente, errada, expirada ou cliente bloqueado | `nao_autenticado` |
| 404 | Não existe, **ou é de outro seller** (nunca revelamos qual dos dois). Seller inexistente na URL também | `nao_encontrado`, `cep_nao_encontrado`, `cotacao_nao_encontrada` |
| 409 | Ação não permitida no estado atual, ou conflito | `status_invalido`, `em_processamento`, `devolucao_ja_existe`, `seller_ja_existe`, `seller_desabilitado` |
| 422 | Dados inválidos ou regra de negócio | `validacao_falhou`, `recurso_nao_suportado`, `sem_pontos_de_retirada`, `cotacao_expirada`, `opcao_invalida`, `cep_diferente_da_cotacao`, `idempotency_key_reutilizada`, `remessa_recusada`, `remessa_nao_entregue`, `transportadora_nao_configurada`, `sem_transportadoras` (nenhuma transportadora ativa cadastrada para o B2C) |
| 429 | Muitas chamadas. Veja o header `Retry-After` (segundos) | `muitas_requisicoes` |
| 502 | A transportadora (ou o ViaCEP) não respondeu direito | `transportadora_indisponivel`, `servico_de_cep_indisponivel` |

Em erros de validação (`422 validacao_falhou`), o campo `detalhes` diz qual campo está errado.
**Campos que a API não conhece são recusados** (evita erro de digitação passar em silêncio).

O limite de chamadas é **por seller** (um lojista muito movimentado não atrapalha os outros). CEP e cadastros contam por cliente da API.
Os valores ficam em `config/frete.php` (e nas variáveis `LIMITE_*` do `.env.docker`).

O que ninguém vê: stack trace, SQL, mensagens internas, texto de erro da transportadora.
Esses detalhes ficam só nos logs e na tabela `chamadas_transportadoras` (com dados pessoais mascarados).

---

## 8. Rodar os testes automáticos

```bash
docker/scripts/test.sh                       # todos
docker/scripts/test.sh --filter Remessa      # só os que têm "Remessa" no nome
docker/scripts/test.sh tests/Unit            # só uma pasta
```

O sistema precisa estar no ar (`up.sh`). Os testes usam um banco separado (`frete_test`) e o banco 1 do Redis,
então **não mexem nos seus dados de desenvolvimento**.

---

## 9. Como o código é organizado

Estrutura padrão do Laravel, parecida com a da intranet:

```
app/
├── Http/
│   ├── Controllers/    Recebem a chamada e devolvem a resposta. Sem regra de negócio.
│   ├── Requests/       Validação dos dados que chegam (um por rota)
│   ├── Resources/      Formato das respostas
│   └── Middleware/     AutenticaCliente (chave), CarregaSeller (seller da URL), request_id, cabeçalhos de segurança
├── Services/           Regras de negócio: SellerService, CotacaoService, RemessaService, ApiKeyService, ViaCep...
├── Models/             Tabelas do banco
├── Transportadoras/    Tudo que é específico de Jadlog e Correios
│   ├── Jadlog.php, Correios.php    Uma classe por transportadora
│   ├── ClienteHttp.php             Faz as chamadas HTTP (timeout, histórico mascarado)
│   └── Mock/                       Simulador e respostas de exemplo
├── Enums/              Status da remessa
└── Support/            Mascaramento de dados sensíveis, conversão de dinheiro
```

Regras que mantemos:

- **Isolamento entre sellers:** toda consulta começa do seller da URL (`$seller->remessas()->findOrFail($id)`).
  Pedir a remessa de um seller na URL de outro dá 404. O seller da URL precisa ser de quem chama (o cliente da API).
- **As credenciais das transportadoras são do cliente da API (B2C)**, e todos os sellers dele as usam. Só entram por comando (`transportadora:configurar`).
- **Dinheiro sempre em centavos** (número inteiro), nunca decimal.
- **URLs das transportadoras** ficam só em `config/transportadoras.php`, nunca vêm de dados enviados pela API.
- **Nenhum segredo** (token, senha, chave) vai para log ou resposta.

Por que a estrutura é simples (e quem é o "cliente da API"): [`docs/adr/0001-estrutura-simples.md`](docs/adr/0001-estrutura-simples.md).

---

## 10. Como adicionar uma transportadora nova

1. Copie `app/Transportadoras/Jadlog.php` (ou `Correios.php`) para um arquivo novo e ajuste URLs, campos e leitura das respostas.
   A classe precisa implementar `Transportadora` (cotar, criar, cancelar, buscar etiqueta e declarar as `regras()` e as `perguntas()` do cadastro de credenciais).
2. Registre o nome em `Transportadoras::LISTA` (`app/Transportadoras/Transportadoras.php`).
   O comando `transportadora:configurar` já passa a aceitar esse nome e a fazer as perguntas dele.
3. Coloque as URLs em `config/transportadoras.php`.
4. Para o modo simulado, acrescente as respostas de exemplo em `Mock/fixtures` e as rotas em `Mock/SimuladorHttp.php`.
5. Copie e adapte os testes de `tests/Feature/Transportadoras`.

Se a transportadora nova também tiver **pontos de retirada**, a classe implementa a interface opcional `PontosDeRetirada`
(`app/Transportadoras/PontosDeRetirada.php`) e a consulta `/pontos-retirada` passa a incluí-la sozinha.

O resto do sistema (cotação, remessa, devolução, rotas) não muda. Há um teste automático que garante isso: ele falha se algum código
fora de `app/Transportadoras/` citar Jadlog ou Correios pelo nome.

---

## 11. Parar e limpar tudo

```bash
docker/scripts/down.sh            # para os containers; banco e arquivos continuam salvos
docker/scripts/down.sh --purge    # APAGA tudo (banco, Redis, etiquetas). Pede confirmação.
```

---

## 12. Antes de ir para produção

Ainda falta fazer:

- [ ] **Só o B2C alcança a API.** Deixar a API na mesma rede/VPC do B2C, com security group (ou lista de IPs) liberando só o B2C.
      A chave de API é a segunda barreira; a primeira é a rede. `APP_HOST_BIND=0.0.0.0` é só para desenvolvimento.
- [ ] **Contas reais:** credenciais da Jadlog e dos Correios (do B2C), cadastradas com `transportadora:configurar`; testar em homologação dos Correios.
      Confirmar com as transportadoras que o **remetente de cada seller pode ser diferente do titular do contrato** (o B2C paga o frete).
- [ ] **Confirmar as premissas** das transportadoras, principalmente o formato da pré-postagem e da etiqueta dos Correios
      e o divisor de cubagem da Jadlog. Lista completa com exemplos: [`docs/premissas-das-transportadoras.md`](docs/premissas-das-transportadoras.md).
- [ ] **Jadlog não tem ambiente de teste:** no modo `real`, criar remessa cria pedido de verdade. Pedir um token de teste à Jadlog.
- [ ] **Etiquetas no S3:** trocar `ETIQUETAS_DISK` para um disco S3.
- [ ] **Redis gerenciado** (ex.: ElastiCache) e **Postgres gerenciado** com `DB_SSLMODE=require`.
- [ ] **Segredos no AWS Secrets Manager** (`APP_KEY`, senhas do banco e do Redis). Hoje ficam no `.env.docker`.
- [ ] **ECS + ALB:** a imagem é a mesma para a API e para o agendador. Configurar `TRUSTED_PROXIES` com o endereço do ALB
      e usar `/up` como health check.
- [ ] **`APP_ENV=production`, `APP_DEBUG=false` e `TRANSPORTADORAS_MODO=real`.**
- [ ] Rotina para **apagar histórico antigo** de chamadas às transportadoras (`RETENCAO_CHAMADAS_DIAS`, padrão 30 dias).
- [ ] Rodar `composer audit` no pipeline.

**Trocar o `APP_KEY` depois de já ter dados** (os segredos e dados pessoais são criptografados com ele):
coloque a chave antiga em `APP_PREVIOUS_KEYS` e a nova em `APP_KEY`. A API passa a ler dados das duas e grava com a nova.

---

## 13. Outros documentos

| Arquivo | O que tem |
|---|---|
| [`docs/fluxo-completo.md`](docs/fluxo-completo.md) | **Comece por aqui**: a jornada de uma compra (CEP → cotação → remessa → etiqueta → devolução) mostrando o código de cada passo, e o **passo a passo para implementar uma transportadora nova** |
| [`docs/guia-do-codigo.md`](docs/guia-do-codigo.md) | Referência por camadas: injeção de dependência, middlewares, Form Request, Service, Eloquent |
| [`docs/glossario.md`](docs/glossario.md) | Os nomes usados no código e o que cada um significa |
| [`docs/premissas-das-transportadoras.md`](docs/premissas-das-transportadoras.md) | O que os PDFs não explicam e o que supusemos |
| [`docs/transportadoras-notas.md`](docs/transportadoras-notas.md) | Resumo dos PDFs da Jadlog e dos Correios |
| [`docs/adr/0001-estrutura-simples.md`](docs/adr/0001-estrutura-simples.md) | Por que o projeto é um Laravel simples e quem é o "cliente da API" |
| [`docs/referencias/`](docs/referencias) | Os PDFs originais das transportadoras |
| [`docs/postman/`](docs/postman) | Collection do Postman |
