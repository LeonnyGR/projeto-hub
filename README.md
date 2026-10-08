# Frete Hub

API de frete para lojistas e B2C, **multi-tenant**. Com ela, cada **cliente** (lojista, B2C...):

- cadastra o **próprio contrato** com a Jadlog e com os Correios;
- consulta o endereço de um CEP e os pontos de retirada;
- cota o frete na **Jadlog** e nos **Correios** ao mesmo tempo;
- cria a remessa, imprime a etiqueta, cancela;
- cria devoluções e lista as remessas e devoluções que já fez.

> **Ainda não está pronto:** o rastreio (`GET /remessas/{id}/rastreio`). É a próxima etapa.

---

## Como funciona (leia primeiro)

**Cliente** é quem usa o hub: um lojista, o B2C... Cada cliente é um "tenant": **tudo é dele** (contrato com as transportadoras,
cotações, remessas) e **ninguém mais enxerga**. Um cliente que pede o dado de outro recebe **404**.

O hub tem **três portas de entrada**. Cada uma é para um tipo de usuário e tem a sua autenticação:

```
 1. Administração   nós (TI do hub)          /api/v1/admin/...    Authorization: Bearer <ADMIN_API_TOKEN>
 2. Chave de API    backend do cliente       /api/v1/...          Authorization: Bearer fs_<prefixo>.<segredo>
 3. Portal          pessoas do cliente       /api/v1/portal/...   login com e-mail e senha -> cookie de sessão
                    (dono e operadores)
```

```
Comprador ─► loja do cliente ─► backend do cliente ──(chave de API)──► Frete Hub ─► Jadlog / Correios
                                                                          ▲
Dono / operador ─► tela do portal ─────────(cookie de sessão)─────────────┘
Nós (TI) ──────────────────────────────────(token de admin)──────────────┘
```

Passo a passo de um cliente novo:

1. **Nós** criamos o cliente na administração (`POST /admin/clientes`). Essa chamada cria **também o usuário dono** do cliente.
2. **O dono** entra no portal (e-mail e senha), cadastra as transportadoras dele e **cria uma chave de API**.
3. **O backend do cliente** (a loja, o B2C) usa essa chave para cotar, criar remessas, imprimir etiquetas...
4. **O dono** também cadastra os **operadores** (funcionários) que usam o portal no dia a dia.

Detalhes que valem saber:

- As portas 2 e 3 usam **as mesmas rotas de negócio** (cotações, remessas, devoluções...). Muda só o prefixo (`/api/v1/...` com chave;
  `/api/v1/portal/...` com cookie) e **quem** está autenticado. Em ambas, o cliente vem da autenticação e **nunca da URL**.
- **O cliente tem a própria conta e o próprio contrato** com Correios e Jadlog: usuário, senha, cartão de postagem, contrato e serviços
  contratados (só PAC, só SEDEX...) dos Correios; token, tomador e serviços da Jadlog. O dono cadastra isso com
  `PUT /transportadoras/{jadlog|correios}` ([seção 3](#3-o-cliente-cadastra-as-transportadoras-dele)).
- A **chave de API** é para **máquina** (o backend do cliente). **Pessoas** usam o portal, com e-mail e senha.

---

## Índice

1. [Como subir o sistema](#1-como-subir-o-sistema)
2. [Cadastrar um cliente e o dono dele (administração)](#2-cadastrar-um-cliente-e-o-dono-dele-administração)
3. [O portal: dono, operadores e chaves de API](#3-o-portal-dono-operadores-e-chaves-de-api)
4. [O cliente cadastra as transportadoras dele](#4-o-cliente-cadastra-as-transportadoras-dele) (catálogo, máscara e "mostrar" com senha)
5. [Testar pelo Postman](#5-testar-pelo-postman)
6. [Usar a API (curl)](#6-usar-a-api-curl)
7. [Modo simulado (mock)](#7-modo-simulado-mock)
8. [Erros da API](#8-erros-da-api)
9. [Rodar os testes automáticos](#9-rodar-os-testes-automáticos)
10. [Como o código é organizado](#10-como-o-código-é-organizado)
11. [Como adicionar uma transportadora nova](#11-como-adicionar-uma-transportadora-nova)
12. [Parar e limpar tudo](#12-parar-e-limpar-tudo)
13. [Antes de ir para produção](#13-antes-de-ir-para-produção)
14. [Outros documentos](#14-outros-documentos)

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

**Já tinha um `.env` ou `.env.docker` de antes?** Confira duas coisas:

- `SESSION_DRIVER` precisa ser **`redis`**. Em arquivos antigos ele era `array`, e com `array` o login do portal **não persiste**
  (você faz o login, e a chamada seguinte já volta 401).
- `ADMIN_API_TOKEN` precisa existir (veja a seção 2.1).

**Já tinha um banco criado antes desta versão?** As migrations foram reescritas (o modelo antigo, com login por id e segredo do cliente e tabelas de lojistas,
não existe mais; agora há as tabelas `chaves_api` e `usuarios`), então o banco antigo não serve. **Só em desenvolvimento**, recrie tudo do zero.
**Isso apaga todos os dados:**

```bash
docker/scripts/migrate.sh --artisan "migrate:fresh --force --database=migrator"
```

Depois, crie o cliente de novo (seção 2). Em produção nunca rode `migrate:fresh`.

**Para acessar de outra máquina** (por exemplo, pelo Postman em `http://devb:8090`):
no `.env.docker`, troque `APP_HOST_BIND=127.0.0.1` por `APP_HOST_BIND=0.0.0.0` e rode `docker/scripts/up.sh --scheduler` de novo.
Se der "connection refused" de outra máquina, a porta TCP 8090 pode estar bloqueada no firewall do servidor.

O banco e o Redis **não ficam abertos** para fora do Docker. Para olhar o banco do seu computador (só em desenvolvimento),
suba com `docker/scripts/up.sh --expose-postgres`.

Outros scripts úteis:

| Script | Para que serve |
|---|---|
| `docker/scripts/logs.sh` | Ver os logs da API (`logs.sh postgres`, `logs.sh redis`, `-f` acompanha ao vivo) |
| `docker/scripts/artisan.sh <comando>` | Rodar um comando artisan (configurar transportadora etc.; clientes são cadastrados pela API de administração, seção 2) |
| `docker/scripts/test.sh` | Rodar os testes automáticos |
| `docker/scripts/down.sh` | Parar tudo (os dados continuam salvos) |

### Variáveis de ambiente que mexem com acesso e sessão

Ficam no `.env.docker` (Docker) ou no `.env` (fora do Docker). Os modelos são `.env.docker.example` e `.env.example`.

| Variável | Para que serve | Padrão |
|---|---|---|
| `ADMIN_API_TOKEN` | Token da administração (porta 1). Vazio ou com menos de 32 caracteres = administração desligada | vazio |
| `SESSION_DRIVER` | Onde ficam as sessões do portal. **Use `redis`**. Com `array` o login não persiste | `redis` |
| `SESSION_LIFETIME` | Minutos de sessão sem uso antes de expirar | `120` |
| `SESSION_SAME_SITE` | `strict`, `lax` ou `none`. Mantenha `strict` (veja seção 3.5) | `strict` |
| `SESSION_SECURE_COOKIE` | Cookie só por HTTPS. Se não definir, liga sozinho com `APP_ENV=production` | automático |
| `SESSION_DOMAIN` | Domínio do cookie. Normalmente vazio | vazio |
| `LIMITE_LOGIN_MIN` | Tentativas de login por minuto, por e-mail + IP (acima disso: 429) | `5` |
| `LIMITE_REVELAR_MIN` | Consultas por minuto, por usuário, de "ver credenciais por inteiro" no portal (acima disso: 429; seção 4.3) | `5` |
| `LIMITE_AUTH_FALHAS_IP` | Falhas de chave de API/token de admin por IP (em 5 min) antes de bloquear o IP | `10` |
| `LIMITE_COTACOES_MIN`, `LIMITE_REMESSAS_MIN`, `LIMITE_CONSULTAS_MIN`, `LIMITE_CADASTROS_MIN` | Requisições por minuto, por cliente | 120 / 30 / 300 / 60 |
| `CORS_ALLOWED_ORIGINS` | Origens de navegador permitidas (separadas por vírgula). Vazio = nenhuma | vazio |
| `CORS_SUPPORTS_CREDENTIALS` | `true` deixa o navegador enviar o cookie de sessão na chamada entre domínios | `false` |
| `TRUSTED_PROXIES` | IP/CIDR do ALB. Vazio = não confia em `X-Forwarded-*` | vazio |

---


## 2. Cadastrar um cliente e o dono dele (administração)

É o passo que **a TI do hub** faz, uma vez para cada cliente novo (lojista, B2C...). É feito pela **API de administração**
(`/api/v1/admin/...`), então ninguém precisa de acesso ao servidor. Ela só aceita o **token de admin**.

### 2.1 O token de admin (`ADMIN_API_TOKEN`)

É uma senha única, guardada no `.env` do servidor. Gere uma com:

```bash
openssl rand -hex 32
```

- No Docker, o `docker/scripts/up.sh` gera o `ADMIN_API_TOKEN` sozinho quando cria o `.env.docker` novo.
  Se você **já tem** um `.env.docker`, acrescente a linha `ADMIN_API_TOKEN=<valor gerado>` e suba a app de novo com `docker/scripts/up.sh`.
- **Vazio ou com menos de 32 caracteres = administração desligada**: toda chamada a `/admin` responde 401.
- Esse token **não** serve como chave de API nem como login de portal, e eles **não** servem na administração.

### 2.2 Criar o cliente (e o dono dele)

```bash
ADMIN_TOKEN="cole-aqui-o-ADMIN_API_TOKEN"

curl -X POST http://127.0.0.1:8090/api/v1/admin/clientes \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "nome": "Loja do João",
    "cnpj": "12.345.678/0001-95",
    "dono": { "nome": "João da Silva", "email": "joao@lojadojoao.com.br", "password": "uma-senha-com-10-ou-mais" }
  }'
```

- `nome` e `cnpj` do cliente são obrigatórios. O CNPJ pode vir com ou sem pontos e traço (guardamos só os números), precisa ser válido
  e **não pode repetir** (CNPJ repetido dá 422).
- `dono` é a pessoa que vai administrar o cliente no portal: `nome`, `email` e `password` são obrigatórios.
  - O **e-mail é único em todo o sistema** (não só dentro do cliente) e é guardado em minúsculas. E-mail repetido dá 422.
  - A **senha tem no mínimo 10 caracteres**. Ela é guardada com bcrypt (ninguém, nem nós, consegue ler a senha depois).
  - Combine com o dono para ele **trocar a senha no primeiro acesso** (`PATCH /portal/eu/senha`, seção 3).

A resposta (201) traz o cliente **e o dono** (a senha nunca aparece):

```json
{
  "data": {
    "id": "01a11213-3c84-712d-850b-49487e26690a",
    "nome": "Loja do João",
    "cnpj": "12345678000195",
    "ativo": true,
    "dono": {
      "id": "01a11213-3c9f-7121-9d2a-2c2f4b0a3e11",
      "nome": "João da Silva",
      "email": "joao@lojadojoao.com.br",
      "papel": "adm_tenant",
      "ativo": true,
      "ultimo_login_em": null
    }
  }
}
```

> O `id` do cliente **não é uma credencial** e não é secreto. Você o usa na URL de `GET/PATCH /admin/clientes/{id}` e como argumento dos comandos `transportadora:*`.

Entregue ao dono o endereço do portal, o e-mail e a senha provisória por um canal seguro. O resto (chave de API, operadores,
transportadoras) o dono faz sozinho, na [seção 3](#3-o-portal-dono-operadores-e-chaves-de-api).

### 2.3 Outras rotas de administração

Todas levam `Authorization: Bearer <ADMIN_API_TOKEN>`:

| Rota | O que faz |
|---|---|
| `POST /api/v1/admin/clientes` | Cria o cliente **e o dono** dele |
| `GET /api/v1/admin/clientes` | Lista os clientes (50 por página): id, nome, CNPJ, ativo |
| `GET /api/v1/admin/clientes/{id}` | Mostra um cliente |
| `PATCH /api/v1/admin/clientes/{id}` | Muda `nome`, `cnpj` e/ou `ativo` (todos opcionais) |

```bash
# bloquear um cliente; use "ativo": true para liberar de novo
curl -X PATCH http://127.0.0.1:8090/api/v1/admin/clientes/ID_DO_CLIENTE \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"ativo": false}'
```

**Desabilitar um cliente (`ativo: false`) bloqueia tudo dele de uma vez:** as chaves de API passam a dar 401 e o dono e os operadores
perdem o acesso ao portal na hora, mesmo com a sessão aberta. Nada é apagado: ao reativar, tudo volta a funcionar.

> Não existe mais "trocar secret" do cliente. Para trocar a credencial do backend, o **dono** cria uma chave de API nova e revoga a antiga
> (seção 3.3).

### Cuidados com o token de admin

- Ele é **mais poderoso** que uma chave de API: cria clientes e donos. Trate como senha de banco.
- Use só em ferramenta interna ou backend. **Nunca** em navegador, app de celular ou código público.
- Em produção (AWS), guarde no Secrets Manager, não em arquivo no servidor.
- Falhas seguidas de autenticação (token de admin ou chave de API) bloqueiam o **IP** por um tempo (429).

---

## 3. O portal: dono, operadores e chaves de API

O portal é a porta das **pessoas** do cliente. Elas entram com **e-mail e senha**, e o hub guarda a sessão num **cookie**.

### 3.1 Papéis: dono e operador

| Papel (`papel`) | Quem é | O que pode fazer |
|---|---|---|
| `adm_tenant` | **Dono** do cliente (criado junto com o cliente) | **Tudo**: cadastrar transportadoras, criar e revogar chaves de API, cadastrar operadores, **e** o dia a dia |
| `operador_tenant` | Funcionário (cadastrado pelo dono) | **Só o dia a dia**: cotar, criar remessas, devoluções, etiquetas, consultas. Nas rotas de dono recebe **403** |

Resumo das rotas do portal (todas começam com `/api/v1/portal`):

| Rota | Quem usa | O que faz |
|---|---|---|
| `POST /login` | qualquer um | Entra com e-mail e senha; devolve o cookie de sessão |
| `POST /logout` | logado | Encerra a sessão |
| `GET /eu` | logado | Mostra quem está logado e de qual cliente |
| `PATCH /eu/senha` | logado | Troca a **própria** senha |
| `GET /chaves`, `POST /chaves`, `DELETE /chaves/{id}` | **só dono** | Lista, cria e revoga chaves de API |
| `GET /usuarios`, `POST /usuarios`, `PATCH /usuarios/{id}` | **só dono** | Lista e cadastra operadores; altera nome, bloqueia e redefine senha |
| `PUT /transportadoras/{t}`, `PATCH /transportadoras/{t}` | **só dono** | Cadastra e liga/desliga o contrato com a transportadora |
| `POST /transportadoras/{t}/credenciais` | **só dono** | Mostra as credenciais **por inteiro**; pede a senha do dono de novo (seção 4.3). Não existe na porta da chave de API |
| `GET /transportadoras/catalogo` | dono e operador | As transportadoras que o hub atende e os campos do formulário de cada uma (seção 4.1) |
| `GET /conta`, `GET /transportadoras`, `GET /transportadoras/{t}`, `GET /enderecos/{cep}`, `GET /pontos-retirada`, `POST /cotacoes`, `GET /cotacoes/{id}`, `GET/POST /remessas`, `GET /remessas/{id}`, `POST /remessas/{id}/despachar`, `POST /remessas/{id}/cancelar`, `GET /remessas/{id}/etiqueta`, `GET/POST /devolucoes`, `GET /devolucoes/{id}`, `GET /devolucoes/{id}/etiqueta` | dono e operador | O dia a dia (as mesmas rotas da chave de API) |

### 3.2 Login, sessão e logout

```bash
PORTAL=http://127.0.0.1:8090/api/v1/portal

# -c cookies.txt: o curl GUARDA o cookie que o hub devolver
curl -s -c cookies.txt -X POST $PORTAL/login \
  -H "Content-Type: application/json" \
  -d '{"email": "joao@lojadojoao.com.br", "password": "uma-senha-com-10-ou-mais"}'

# -b cookies.txt: o curl ENVIA o cookie guardado (é isso que prova que você está logado)
curl -s -b cookies.txt $PORTAL/eu
curl -s -b cookies.txt -X POST $PORTAL/logout
```

O login devolve os dados do usuário (200). O `GET /eu` mostra também o cliente:

```json
{
  "data": {
    "id": "01a11213-3c9f-7121-9d2a-2c2f4b0a3e11",
    "nome": "João da Silva",
    "email": "joao@lojadojoao.com.br",
    "papel": "adm_tenant",
    "ativo": true,
    "ultimo_login_em": "2026-10-08T12:00:00+00:00",
    "cliente": { "id": "01a11213-3c84-712d-850b-49487e26690a", "nome": "Loja do João", "cnpj": "12345678000195" }
  }
}
```

Para trocar a própria senha (a nova precisa ter 10 ou mais caracteres e ser diferente da atual):

```bash
curl -s -b cookies.txt -X PATCH $PORTAL/eu/senha -H "Content-Type: application/json" \
  -d '{"senha_atual": "uma-senha-com-10-ou-mais", "nova_senha": "outra-senha-bem-longa"}'
# resposta: 204 (sem corpo)
```

Como a sessão funciona:

- Ao fazer login, o hub cria uma **sessão no servidor** (guardada no **Redis**, por isso `SESSION_DRIVER=redis`) e manda ao navegador um **cookie**
  com o número dela. Todo o resto é identificado por esse cookie. A sessão expira depois de `SESSION_LIFETIME` minutos sem uso (padrão 120).
- O cookie é **`HttpOnly`**: o JavaScript da página **não consegue ler** o cookie. Se alguém conseguir injetar um script no portal, ele não rouba a sessão.
- O cookie é **`SameSite=Strict`**: o navegador só o envia quando a chamada parte do **mesmo site**. E é **`Secure`** em produção (só trafega em HTTPS).
- Usuário **bloqueado** (`ativo: false`) ou cliente **desabilitado** perde o acesso **na hora**, mesmo com a sessão aberta.
- **Limite de login:** no máximo **5 tentativas por minuto** por combinação e-mail + IP (`LIMITE_LOGIN_MIN`). Passou disso: **429**.
- **Qualquer falha de login dá o mesmo 401** (e-mail que não existe, senha errada, usuário bloqueado, cliente desabilitado). Assim ninguém descobre
  quais e-mails existem.

### 3.3 Chaves de API (só o dono)

A chave de API é a credencial do **backend do cliente** (a loja, o B2C) para chamar a porta 2. **Só o dono cria chaves.**

```bash
curl -s -b cookies.txt -X POST $PORTAL/chaves -H "Content-Type: application/json" -d '{"nome": "producao"}'
```

Resposta (201):

```json
{
  "data": {
    "id": "01a11213-4d10-7a21-8c3e-5f0e9b7a1c22",
    "nome": "producao",
    "prefixo": "fs_3fa91c0b2d4e",
    "ultimo_uso_em": null,
    "revogada_em": null,
    "criada_em": "2026-10-08T12:05:00+00:00",
    "chave": "fs_3fa91c0b2d4e.k8JxQe7y0Zp3...resto-do-segredo"
  }
}
```

**Como é uma chave:** `fs_<prefixo>.<segredo>`.

- O **prefixo** (`fs_3fa91c0b2d4e`) é **público**: serve para o hub achar a chave no banco (e, por ela, descobrir o cliente) e para você
  reconhecer a chave numa lista.
- O **segredo** (o que vem depois do ponto) é aleatório e secreto. No banco guardamos **só o hash (sha256)** dele.
- **O texto completo (`chave`) aparece uma única vez, nesta resposta.** Copie e guarde no cofre de segredos do backend. Se perder, **não há como
  recuperar**: crie outra e revogue a antiga.
- Um cliente pode ter **várias chaves** ao mesmo tempo (ex.: `producao` e `homologacao`), cada uma revogável sozinha.

Listar e revogar:

```bash
curl -s -b cookies.txt $PORTAL/chaves                          # lista (sem o segredo): id, nome, prefixo, ultimo_uso_em, revogada_em
curl -s -b cookies.txt -X DELETE $PORTAL/chaves/ID_DA_CHAVE    # revoga: quem a usa passa a receber 401
```

**Trocar a chave sem parar nada** (rotação):

1. Crie uma chave nova (`POST /chaves`).
2. Coloque a chave nova no cofre/`.env` do backend e publique.
3. Veja em `GET /chaves` que a **chave antiga** parou de ter `ultimo_uso_em` recente.
4. Revogue a antiga (`DELETE /chaves/{id}`).

**Usar a chave** (porta 2):

```bash
URL=http://127.0.0.1:8090/api/v1
CHAVE="fs_3fa91c0b2d4e.k8JxQe7y0Zp3...resto-do-segredo"

curl -s -H "Authorization: Bearer $CHAVE" $URL/conta
```

Chave errada, revogada ou de cliente desabilitado: sempre o mesmo **401**.

### 3.4 Operadores (só o dono)

O dono cadastra os funcionários que usam o portal no dia a dia:

```bash
# criar
curl -s -b cookies.txt -X POST $PORTAL/usuarios -H "Content-Type: application/json" \
  -d '{"nome": "Maria Operadora", "email": "maria@lojadojoao.com.br", "password": "senha-provisoria-1"}'

curl -s -b cookies.txt $PORTAL/usuarios                       # lista os usuários do cliente (dono e operadores)

# bloquear um operador (ativo=false) ou redefinir a senha dele (password). Todos os campos são opcionais.
curl -s -b cookies.txt -X PATCH $PORTAL/usuarios/ID_DO_OPERADOR -H "Content-Type: application/json" -d '{"ativo": false}'
```

- O `POST` devolve **201** com o usuário (`papel: "operador_tenant"`).
- O `PATCH` aceita `nome`, `ativo` e `password`. Só mexe em **operadores do mesmo cliente**: o dono e usuários de outro cliente dão 404.
- Mesmas regras do dono: e-mail único no sistema (guardado em minúsculas) e senha com no mínimo 10 caracteres.
- Operador que chamar essas rotas (ou chaves, ou `PUT/PATCH /transportadoras`) recebe **403 `proibido`**.

### 3.5 Usar o portal de outro domínio (CORS e cookie)

Se a tela do portal roda em **outro endereço** que o hub (ex.: portal em `portal.bartofil.com.br` e hub em `hub.bartofil.com.br`), duas coisas são necessárias.

**1. Liberar o CORS no hub** (`.env`):

```
CORS_ALLOWED_ORIGINS=https://portal.bartofil.com.br   # a origem EXATA (com https://, sem barra no final)
CORS_SUPPORTS_CREDENTIALS=true                         # deixa o navegador enviar o cookie
```

E o front precisa mandar o cookie: `fetch(url, { credentials: "include" })` (ou `withCredentials: true` no axios).

**2. O portal precisa ser "same-site" com o hub.** "Site" = o **domínio registrável**: `portal.bartofil.com.br` e `hub.bartofil.com.br` são o mesmo site
(`bartofil.com.br`), então o cookie `SameSite=Strict` funciona. Já `meuportal.com` chamando `hub.bartofil.com.br` é **cross-site**: o navegador
**não envia** o cookie e o login "não pega". Nesse caso a decisão de segurança precisa ser revista (veja abaixo) antes de mexer em `SESSION_SAME_SITE`.

**Por que não há token CSRF?** CSRF é o ataque em que outro site faz o navegador do usuário chamar o hub usando o cookie dele. Aqui isso fica barrado por dois motivos:
o cookie é `SameSite=Strict` (o navegador não o envia quando a chamada vem de outro site) e a API só aceita **JSON** (um formulário de outro site não consegue enviar JSON).
**Se o portal for cross-site**, essas duas proteções deixam de valer como estão e é preciso rever (token CSRF, `SameSite=None; Secure`, etc.).

---

## 4. O cliente cadastra as transportadoras dele

O contrato com a Jadlog e com os Correios é feito **entre o cliente e a transportadora** (comercial, Meu Correios, catálogo dos Correios).
Aqui guardamos uma cópia **criptografada** dos dados que a transportadora entregou. **Cada cliente tem o dele**, e as cotações e remessas
do cliente usam sempre o contrato dele. Quem paga o frete é o titular do contrato, ou seja, o próprio cliente.

### 4.1 Por API (é o que a tela do portal chama)

Quem faz isso é o **dono**. Pelo portal (com o cookie do login, seção 3.2) a rota é `PUT /api/v1/portal/transportadoras/{t}`;
pela porta da chave de API a rota é `PUT /api/v1/transportadoras/{t}`. O corpo é o mesmo. O exemplo usa a chave de API:

```bash
URL=http://127.0.0.1:8090/api/v1
CHAVE="fs_3fa91c0b2d4e.k8JxQe7y0Zp3...resto-do-segredo"      # a chave de API do cliente (seção 3.3)

curl -s -H "Authorization: Bearer $CHAVE" -X PUT $URL/transportadoras/correios -H "Content-Type: application/json" -d '{
  "credenciais":  { "usuario": "meu-usuario", "senha": "minha-senha", "cartao_postagem": "0012345678" },
  "configuracao": { "contrato": "9912345678", "servicos": [ {"codigo": "03220", "nome": "SEDEX"}, {"codigo": "04669", "nome": "PAC"} ] }
}'
```

Neste exemplo o cliente só contratou SEDEX e PAC: só esses dois serviços aparecerão nas cotações dele.

- `PUT /transportadoras/{jadlog|correios}`: cadastra (**201**) ou substitui (**200**). Os campos de cada transportadora estão nas tabelas abaixo
  (`credenciais` = segredos, `configuracao` = o resto). Dados inválidos dão **422** e nada é salvo.
- **Editar uma transportadora já cadastrada** (o fluxo da tela de edição):
  1. `GET /transportadoras/{t}` devolve a `configuracao` inteira e as `credenciais_mascaradas`: com isso a tela preenche o formulário.
  2. A pessoa muda o que quiser e a tela manda `PUT /transportadoras/{t}` com a `configuracao` completa.
  3. **Nas `credenciais`, só vai o que a pessoa digitou de novo.** Campo que não vier, ou vier em branco, **mantém o valor salvo**. Exemplo: para trocar só a senha dos Correios, mande `"credenciais": {"senha": "nova"}`; o usuário e o cartão continuam como estavam. Sem `credenciais` nenhuma, todas continuam.
  - No **primeiro cadastro** todas as credenciais continuam obrigatórias.
  - Para ver o valor inteiro de uma credencial antes de editar, o dono usa "mostrar" (seção 4.3).
- `PATCH /transportadoras/{t}` com `{"ativa": false}` tira a transportadora das cotações (e `true` volta a usar), sem apagar os dados.
  Se a transportadora ainda não foi cadastrada: 404 `transportadora_nao_cadastrada`.
- Operador do portal **não** pode cadastrar nem ligar/desligar transportadora (403); só o dono.
- `GET /transportadoras` lista o que **este cliente** cadastrou. **Token e senha nunca voltam por inteiro nesta rota**: só `credenciais_cadastradas: true`
  e `credenciais_mascaradas` (veja a seção 4.3).

#### O catálogo: de onde vem o formulário

A tela do portal **não tem campos fixos**. Ela pergunta ao hub quais transportadoras existem e quais campos cada uma pede:

```bash
curl -s -H "Authorization: Bearer $CHAVE" $URL/transportadoras/catalogo      # no portal: curl -s -b cookies.txt $PORTAL/transportadoras/catalogo
```

Resposta (resumida: só três campos da Jadlog e um dos Correios):

```json
{ "data": [
  { "codigo": "jadlog", "nome": "Jadlog", "campos": [
    { "campo": "credenciais.token", "grupo": "credenciais", "titulo": "Token da Jadlog", "tipo": "secreto",
      "obrigatorio": true, "padrao": null, "ajuda": null, "opcoes": null },
    { "campo": "configuracao.tomador.nome", "grupo": "configuracao", "titulo": "Tomador do frete (titular do contrato): nome ou razao social", "tipo": "texto",
      "obrigatorio": true, "padrao": null, "ajuda": null, "opcoes": null },
    { "campo": "configuracao.tipo_coleta", "grupo": "configuracao", "titulo": "Tipo de coleta", "tipo": "select",
      "obrigatorio": false, "padrao": "K", "ajuda": null,
      "opcoes": { "K": "A Jadlog coleta no lojista", "S": "O lojista leva ate a Jadlog" } }
  ] },
  { "codigo": "correios", "nome": "Correios", "campos": [ { "campo": "credenciais.usuario", "...": "..." } ] }
] }
```

O que cada informação do campo quer dizer:

| Item | Significado |
|---|---|
| `campo` | Onde o valor entra no `PUT`, com **caminho pontuado**. `credenciais.token` vira `{"credenciais": {"token": "..."}}`; `configuracao.tomador.endereco.cep` vira `{"configuracao": {"tomador": {"endereco": {"cep": "..."}}}}` |
| `grupo` | `credenciais` (segredos, guardados criptografados) ou `configuracao` (o resto) |
| `titulo` | O nome para mostrar na tela |
| `tipo` | `texto`; `secreto` (campo de senha, o valor não deve aparecer na tela); `servicos` (lista de `{codigo, nome}` dos serviços contratados); `select` (escolher uma das `opcoes`) |
| `obrigatorio` | `true` se a validação do cadastro exige o campo (vem das `regras()` da classe) |
| `padrao` | Valor sugerido, ou `null` |
| `ajuda` | Dica de preenchimento, ou `null` |
| `opcoes` | Só no `select`: `{"valor": "texto para mostrar"}`. Senão `null` |

O catálogo é definido **no código** (em cada classe de transportadora), não numa tabela do banco. O hub só "atende" uma transportadora que tem
código de integração; por isso não adianta cadastrar uma no banco. Para incluir uma nova, veja a seção 11.

#### O fluxo da tela, de ponta a ponta

1. `GET /transportadoras/catalogo`: a tela desenha o formulário (um campo para cada item de `campos`).
2. O dono preenche e a tela monta o corpo e chama `PUT /transportadoras/{codigo}`.
3. `GET /transportadoras`: a tela lista o que foi cadastrado, com as credenciais **mascaradas**.
4. Se o dono clicar em "mostrar" numa credencial, a tela pede a **senha dele de novo** e chama `POST /portal/transportadoras/{codigo}/credenciais` (seção 4.3).

### 4.2 Pelo terminal (mesmo cadastro, para a TI)

```bash
docker/scripts/artisan.sh transportadora:configurar jadlog  <id-do-cliente>
docker/scripts/artisan.sh transportadora:configurar correios <id-do-cliente>
```

O comando faz as perguntas. O que você digita em campo secreto (token, senha, cartão) **não aparece na tela** e não fica no histórico do terminal.
Se existir só um cliente ativo, o id do cliente pode ser omitido; com mais de um, ele é obrigatório (`GET /admin/clientes` mostra os ids).
Se os dados já existirem, o comando pergunta antes de substituir. Se algum dado for inválido (CNPJ errado, UF inexistente...), ele mostra o que está errado e **não salva nada**.

**Jadlog** pergunta:

| Pergunta | O que responder |
|---|---|
| Token da Jadlog | O token que a Jadlog entregou (secreto) |
| Tomador: nome, CNPJ, telefone, e-mail, endereço | **Quem paga o frete** (o titular do contrato, ou seja, o cliente). A Jadlog exige os dados completos |
| Código do cliente | Código do cliente na Jadlog |
| Conta corrente / Contrato / Unidade | Se tiver. Se não, aperte Enter |
| Tipo de coleta | `K` = a Jadlog coleta no cliente (padrão), `S` = o cliente leva até a Jadlog |
| Modalidades contratadas | Formato `código:nome`, separadas por vírgula. Ex.: `3:Package,0:Expresso` |

**Correios** pergunta: usuário do Meu Correios, senha de acesso à API (secreta), número do cartão de postagem (secreto),
contrato (opcional) e serviços contratados (ex.: `03220:SEDEX,04669:PAC`).

Outros comandos:

```bash
docker/scripts/artisan.sh transportadora:ativar jadlog <id-do-cliente> --desativar   # tira a Jadlog das cotações desse cliente, sem apagar os dados
docker/scripts/artisan.sh transportadora:ativar jadlog <id-do-cliente>               # volta a usar
```

> Em **modo simulado** (padrão em desenvolvimento) pode usar dados de mentira: nenhuma transportadora real é chamada.

### 4.3 Ver as credenciais: máscara e "mostrar" com senha

**Na listagem: sempre mascaradas.** `GET /transportadoras` (e as respostas de `PUT` e `PATCH`) trazem `credenciais_mascaradas`:

```json
{ "data": [ { "transportadora": "jadlog", "ativa": true, "credenciais_cadastradas": true,
              "credenciais_mascaradas": { "token": "••••••a9f3" },
              "configuracao": { "...": "..." }, "atualizado_em": "2026-10-08T14:00:00-03:00" } ] }
```

A regra da máscara: valor com **12 caracteres ou mais** mostra 6 bolinhas e os **4 últimos** caracteres (`••••••1234`);
valor mais curto mostra só **8 bolinhas** (`••••••••`), porque mostrar o final de um valor curto ajudaria a adivinhá-lo.
O valor inteiro nunca aparece nessa rota.

**"Mostrar" por inteiro (só portal, só dono):**

```bash
curl -s -b cookies.txt -X POST $PORTAL/transportadoras/jadlog/credenciais -H "Content-Type: application/json" -d '{ "password": "senha-do-dono" }'
```

```json
{ "data": { "transportadora": "jadlog", "credenciais": { "token": "o-token-inteiro-da-jadlog" } } }
```

Regras desta rota:

- **Pede a senha do dono de novo**, mesmo com ele já logado. Assim, uma sessão esquecida aberta num computador não basta para ver os segredos.
- Senha errada: **422** `senha_incorreta`. Operador: **403**. Transportadora ainda não cadastrada: 404 `transportadora_nao_cadastrada`.
- **Só existe no portal.** Pela porta da chave de API essa rota não existe (404): quem tem só a chave nunca consegue ver os segredos.
- **Tem limite:** `LIMITE_REVELAR_MIN` consultas por minuto por usuário (padrão **5**). Passou disso: **429**.
- **Cada consulta fica registrada no log** (usuário, cliente e transportadora; **nunca** o segredo).
- O cliente cadastra e vê as credenciais **dele** (Correios/Jadlog). Já as **chaves de API do hub** (`fs_...`) continuam sendo mostradas por inteiro **só na criação** (seção 3.3).

### 4.4 A conta do cliente

```bash
curl -s -H "Authorization: Bearer $CHAVE" $URL/conta      # no portal: curl -s -b cookies.txt $PORTAL/conta
```

```json
{ "data": { "id": "01a11213-3c84-712d-850b-49487e26690a", "nome": "Loja do João", "cnpj": "12345678000195", "ativo": true } }
```

- A conta só mostra quem é o cliente. O CEP de origem não faz parte dela: vai em cada cotação.
- O campo `id` é o id do cliente. Nenhuma resposta traz segredo (nem chave de API, depois da criação, nem senha).
- `GET /conta` existe nas duas portas (chave de API e portal).

---

## 5. Testar pelo Postman

1. No Postman: **Import** → **Raw text** → cole o conteúdo de
   [`docs/postman/frete-hub.postman_collection.json`](docs/postman/frete-hub.postman_collection.json) → **Import**.
2. Abra a collection **Frete Hub** → aba **Variables** e preencha:
   - `base_url`: o endereço da API (já vem `http://devb:8090`);
   - `admin_token`: o `ADMIN_API_TOKEN` (só para nós).
3. Monte o ambiente de teste **nesta ordem**:
   1. **`0 - Administracao (so nos)` > Criar cliente (com dono)**: cria o cliente e o dono. O script guarda o `cliente_id` sozinho.
      (Mude o e-mail do dono no corpo se ele já existir: e-mail é único.)
   2. **`Portal` > Login (dono)**: entra com o e-mail e a senha do dono. **O Postman guarda o cookie sozinho** e o reenvia nas próximas chamadas ao mesmo `base_url`.
   3. **`Portal` > Criar chave de API**: o script guarda o texto da chave na variável **`api_key`**. As pastas `1` a `7` usam essa variável (`Authorization: Bearer {{api_key}}`,
      já configurado na collection: não precisa mexer na aba **Authorization**).
4. Rode as pastas **na ordem**: `1 - Conta`, `2 - Transportadoras do cliente` (cadastra o contrato de teste com Jadlog e Correios),
   `3 - Consultas`, `4 - Cotações`, `5 - Remessas`, `6 - Devoluções`. As variáveis `cotacao_id`, `remessa_id`... se preenchem sozinhas.
   Para testar como outro cliente, crie outro cliente (muda o e-mail) e repita o passo 3.

Observações:

- A pasta **`Portal`** também testa operadores: **Criar operador**, listar, bloquear. Para ver o **403**, faça login como operador e tente `Listar chaves de API`.
- Se o login "não pega" (a chamada seguinte dá 401), confira se `SESSION_DRIVER=redis` no `.env.docker` (seção 1).
- **Criar remessa** gera uma `Idempotency-Key` nova a cada envio. **"Criar remessa (repetir a mesma chamada)"** reenvia a mesma chave
  e mostra que a API devolve a remessa que já existe, sem criar outra.
- **Devoluções** só funcionam para remessa **entregue**. Enquanto o rastreio não está pronto, o resultado esperado é o erro
  `422 remessa_nao_entregue`.
- A pasta **7 - Cenários do simulador** testa falhas (timeout, erro 500...). Veja a tabela na seção 7.

---

## 6. Usar a API (curl)

Defina duas variáveis no terminal. Todas as rotas começam com `$URL` e usam o header `$AUTH` para autenticar com a **chave de API**
(a que o dono criou, seção 3.3):

```bash
URL=http://127.0.0.1:8090/api/v1
CHAVE="fs_3fa91c0b2d4e.k8JxQe7y0Zp3...resto-do-segredo"
AUTH="Authorization: Bearer $CHAVE"
```

Todos os exemplos abaixo usam `-H "$AUTH"`. **Pelo portal** (com login) as rotas são as mesmas, com o prefixo `/api/v1/portal`:
troque `$URL` por `$PORTAL` e `-H "$AUTH"` por `-b cookies.txt`. Exemplo: `curl -s -b cookies.txt $PORTAL/remessas`.

### Consultar CEP e pontos de retirada

```bash
curl -s -H "$AUTH" $URL/enderecos/01001000
curl -s -H "$AUTH" "$URL/pontos-retirada?cep=02031100&tipos=LOCKER,JADLOG"
```

O CEP é consultado no **ViaCEP de verdade**, mesmo em modo simulado (e o resultado é guardado por 7 dias).

Os pontos de retirada vêm de **todas as transportadoras ativas que oferecem isso** (hoje, só a Jadlog); cada ponto diz de qual transportadora é
(`"transportadora": "jadlog"`). Se uma transportadora não responder, ela aparece em `falhas` e as outras continuam.
Filtros opcionais: `transportadora=jadlog` (só uma) e `tipos=LOCKER,PUBLICO` (cada transportadora usa os tipos que conhece).
Se nenhuma transportadora ativa oferecer pontos de retirada, a resposta é 422 `sem_pontos_de_retirada`.

### Cotar o frete

```bash
curl -s -H "$AUTH" -X POST $URL/cotacoes -H "Content-Type: application/json" -d '{
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
- `cep_origem` é **obrigatório em toda cotação**: quem chama (o B2C) informa de onde o pacote sai. O hub não guarda CEP de origem no cadastro do cliente.
- Vários vendedores/origens? Faça **uma chamada `POST /cotacoes` por origem**, cada uma com o `cep_origem` do vendedor (e os volumes dele). O B2C junta as respostas: cada cotação tem o seu `cotacao_id`, e a remessa usa o `cotacao_id` da origem correspondente.
- Só entram na cotação as transportadoras **ativas deste cliente**, e só os serviços que ele cadastrou (ex.: só PAC e SEDEX).
- Se uma transportadora não responder, ela some de `opcoes` e aparece em `falhas` com o motivo
  (`sem_resposta`, `credencial_invalida`, `erro_na_transportadora`, `resposta_invalida` ou `sem_cobertura`). As outras continuam normalmente.

### Criar a remessa

Use o `id` da cotação e uma das opções dela. O header **`Idempotency-Key` é obrigatório**: invente um valor único por remessa
(o id do pedido da loja serve). Se a chamada for repetida com a mesma chave, a API devolve a remessa que já existe.

```bash
curl -s -H "$AUTH" -X POST $URL/remessas -H "Content-Type: application/json" \
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

### Listar remessas e devoluções

```bash
curl -s -H "$AUTH" $URL/remessas                       # as mais novas primeiro, 50 por página (?page=2 para a próxima)
curl -s -H "$AUTH" "$URL/remessas?status=criada"       # só as que estão com esse status
curl -s -H "$AUTH" "$URL/devolucoes?status=entregue"   # o mesmo para as devoluções
```

Só aparecem as remessas **deste cliente**. O `status` aceita os valores da [seção de status](docs/glossario.md#status-da-remessa) (`criada`, `despachada`, `em_transito`...);
um valor desconhecido dá 422.

### Etiqueta, despachar e cancelar

```bash
R=$URL/remessas/ID-DA-REMESSA
curl -s -H "$AUTH" $R/etiqueta -o etiqueta.pdf        # PDF para imprimir
curl -s -H "$AUTH" -X POST $R/despachar            # o cliente entregou o pacote à transportadora
curl -s -H "$AUTH" -X POST $R/cancelar             # só se criada ou despachada
curl -s -H "$AUTH" $R                              # ver a remessa
```

Status da remessa: `criada` → `despachada` → `em_transito` → `saiu_para_entrega` → `entregue` (ou `cancelada`).
Para entender cada um, veja o [glossário](docs/glossario.md).

### Devolução

Só para remessa **entregue**. A devolução é uma nova remessa no sentido contrário (do comprador para a loja), na mesma transportadora.

```bash
D=$URL/devolucoes
curl -s -H "$AUTH" -X POST $D -H "Content-Type: application/json" \
  -H "Idempotency-Key: devolucao-pedido-1001" -d '{"remessa_id": "ID-DA-REMESSA", "motivo": "Produto com defeito"}'

curl -s -H "$AUTH" $D/ID
curl -s -H "$AUTH" $D/ID/etiqueta -o etiqueta-devolucao.pdf
```

---

## 7. Modo simulado (mock)

Serve para desenvolver e testar **sem conta real** na Jadlog nem nos Correios. É o padrão em desenvolvimento
(`TRANSPORTADORAS_MODO=mock` no `.env.docker`).

- O código da Jadlog e dos Correios roda de verdade; só a resposta da internet é simulada, a partir de arquivos copiados dos exemplos dos PDFs.
- **O modo simulado não sobe com `APP_ENV=production`** (de propósito, para nunca ir para produção por engano).
- Para falar com as transportadoras de verdade: `TRANSPORTADORAS_MODO=real` (veja a seção 13 antes).

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

## 8. Erros da API

Todo erro tem o mesmo formato:

```json
{ "erro": { "codigo": "cotacao_expirada", "mensagem": "A cotacao expirou. Faca uma nova cotacao." }, "request_id": "9f2c..." }
```

O `request_id` também vem no header `X-Request-Id` e aparece nos logs. Se alguém reclamar de um erro, peça esse número.

| Status | Quando acontece | Códigos mais comuns |
|---|---|---|
| 401 | **Chave de API** ausente, errada, revogada ou de cliente desabilitado. **Portal:** sem login, sessão expirada, e-mail ou senha errados, usuário bloqueado ou cliente desabilitado. **`/admin`:** token ausente, errado ou administração desligada. Sempre a mesma resposta, sem dizer o motivo | `nao_autenticado` |
| 403 | Logado no portal, mas o **papel não permite** (operador tentando chaves, operadores, `PUT/PATCH /transportadoras` ou ver credenciais por inteiro) | `proibido` |
| 404 | Não existe, **ou é de outro cliente** (nunca revelamos qual dos dois) | `nao_encontrado`, `cep_nao_encontrado`, `cotacao_nao_encontrada`, `transportadora_nao_cadastrada` |
| 409 | Ação não permitida no estado atual, ou conflito | `status_invalido`, `em_processamento`, `devolucao_ja_existe` |
| 422 | Dados inválidos ou regra de negócio | `validacao_falhou`, `recurso_nao_suportado`, `sem_pontos_de_retirada`, `cotacao_expirada`, `opcao_invalida`, `cep_diferente_da_cotacao`, `idempotency_key_reutilizada`, `remessa_recusada`, `remessa_nao_entregue`, `transportadora_nao_configurada`, `senha_incorreta` (senha errada ao ver credenciais por inteiro), `sem_transportadoras` (o cliente não tem nenhuma transportadora ativa cadastrada) |
| 429 | Muitas chamadas, **ou** muitas tentativas de login (5 por minuto por e-mail + IP), **ou** muitas consultas de "ver credenciais por inteiro" (5 por minuto por usuário), **ou** muitas falhas de autenticação seguidas do mesmo IP. Veja o header `Retry-After` (segundos) | `muitas_requisicoes` |
| 502 | A transportadora (ou o ViaCEP) não respondeu direito | `transportadora_indisponivel`, `servico_de_cep_indisponivel` |

Em erros de validação (`422 validacao_falhou`), o campo `detalhes` diz qual campo está errado.
**Campos que a API não conhece são recusados** (evita erro de digitação passar em silêncio).

O limite de chamadas é **por cliente** (um cliente muito movimentado não atrapalha os outros). Cada tipo de chamada (cotações, remessas, consultas, cadastros) tem o seu contador.
Errar a chave de API (ou o token de admin) muitas vezes seguidas bloqueia o **IP** por um tempo (429). O login do portal tem limite próprio (`LIMITE_LOGIN_MIN`, padrão 5 por minuto por e-mail + IP).
Os valores ficam em `config/frete.php` (e nas variáveis `LIMITE_*` do `.env.docker`).

O que ninguém vê: stack trace, SQL, mensagens internas, texto de erro da transportadora.
Esses detalhes ficam só nos logs e na tabela `chamadas_transportadoras` (com dados pessoais mascarados).

---

## 9. Rodar os testes automáticos

```bash
docker/scripts/test.sh                       # todos
docker/scripts/test.sh --filter Remessa      # só os que têm "Remessa" no nome
docker/scripts/test.sh tests/Unit            # só uma pasta
```

O sistema precisa estar no ar (`up.sh`). Os testes usam um banco separado (`frete_test`) e o banco 1 do Redis,
então **não mexem nos seus dados de desenvolvimento**.

---

## 10. Como o código é organizado

Estrutura padrão do Laravel, parecida com a da intranet:

```
app/
├── Http/
│   ├── Controllers/    Recebem a chamada e devolvem a resposta. Sem regra de negócio. (Admin/ = administração, Portal/ = login, chaves e operadores)
│   ├── Requests/       Validação dos dados que chegam (um por rota)
│   ├── Resources/      Formato das respostas
│   └── Middleware/     AutenticaAdmin (token de admin), AutenticaCliente (chave de API), AutenticaPortal (cookie de sessão), ExigePapel (dono x operador), request_id, cabeçalhos de segurança
├── Services/           Regras de negócio: ClienteService, ChaveApiService, UsuarioService, TransportadoraService, CotacaoService, RemessaService, ConsultasService, ViaCep...
├── Models/             Tabelas do banco
├── Transportadoras/    Tudo que é específico de Jadlog e Correios
│   ├── Jadlog.php, Correios.php    Uma classe por transportadora
│   ├── ClienteHttp.php             Faz as chamadas HTTP (timeout, histórico mascarado)
│   └── Mock/                       Simulador e respostas de exemplo
├── Enums/              Status da remessa
└── Support/            Mascaramento de dados sensíveis, conversão de dinheiro
```

Regras que mantemos:

- **Isolamento entre clientes:** toda consulta parte do cliente logado (`$cliente->remessas()->findOrFail($id)`).
  Pedir a remessa de outro cliente dá 404, igual a uma que não existe.
- **Três portas, uma regra de negócio:** as rotas de negócio são definidas **uma vez** em `routes/api.php` e montadas sob a chave de API e sob o portal. O dono tem a rota extra de configuração; o operador, não.
- **As credenciais das transportadoras são de cada cliente** (tabela `credenciais_transportadoras`, ligada ao `cliente_id`). Entram por API (`PUT /transportadoras/{t}`) ou pelo comando `transportadora:configurar`; token e senha só voltam mascarados (`credenciais_mascaradas`); por inteiro, só o dono, no portal, com a senha dele (seção 4.3).
- **Tabelas principais:** `clientes` (`id`, `nome`, `cnpj` único, `ativo`), `chaves_api` (prefixo e hash do segredo, `revogada_em`), `usuarios` (e-mail único, senha em hash, `papel`, `ativo`),
  `credenciais_transportadoras`, `cotacoes`, `remessas` e `chamadas_transportadoras`. Todas, menos `clientes`, têm `cliente_id`.
- **Dinheiro sempre em centavos** (número inteiro), nunca decimal.
- **URLs das transportadoras** ficam só em `config/transportadoras.php`, nunca vêm de dados enviados pela API.
- **Nenhum segredo** (token das transportadoras, senha, chave de API, token de admin) vai para log ou resposta. A chave de API só aparece inteira na resposta que a cria.

Por que a estrutura é simples (e por que o hub é multi-tenant): [`docs/adr/0001-estrutura-simples.md`](docs/adr/0001-estrutura-simples.md).

---

## 11. Como adicionar uma transportadora nova

1. Copie `app/Transportadoras/Jadlog.php` (ou `Correios.php`) para um arquivo novo e ajuste URLs, campos e leitura das respostas.
   A classe precisa implementar `Transportadora` (cotar, criar, cancelar, buscar etiqueta e declarar `nome()`, `regras()` e `perguntas()` do cadastro de credenciais).
2. Registre o nome em `Transportadoras::LISTA` (`app/Transportadoras/Transportadoras.php`).
   O comando `transportadora:configurar` já passa a aceitar esse nome e a fazer as perguntas dele, e o **formulário do portal aparece sozinho**
   (o `GET /transportadoras/catalogo` lê `nome()`, `perguntas()` e `regras()` da classe; não há tela nem tabela para mexer).
3. Coloque as URLs em `config/transportadoras.php`.
4. Para o modo simulado, acrescente as respostas de exemplo em `Mock/fixtures` e as rotas em `Mock/SimuladorHttp.php`.
5. Copie e adapte os testes de `tests/Feature/Transportadoras`.

Se a transportadora nova também tiver **pontos de retirada**, a classe implementa a interface opcional `PontosDeRetirada`
(`app/Transportadoras/PontosDeRetirada.php`) e a consulta `/pontos-retirada` passa a incluí-la sozinha.

O resto do sistema (cotação, remessa, devolução, rotas) não muda. Há um teste automático que garante isso: ele falha se algum código
fora de `app/Transportadoras/` citar Jadlog ou Correios pelo nome.

---

## 12. Parar e limpar tudo

```bash
docker/scripts/down.sh            # para os containers; banco e arquivos continuam salvos
docker/scripts/down.sh --purge    # APAGA tudo (banco, Redis, etiquetas). Pede confirmação.
```

---

## 13. Antes de ir para produção

Ainda falta fazer:

- [ ] **A API só deve ser alcançável por quem precisa.** Rede privada (VPC) com security group (ou lista de IPs) para as portas **1 (admin)** e **2 (backends dos clientes)**;
      a porta 3 (portal) é acessada pelo navegador das pessoas, então fica atrás do ALB com HTTPS. `APP_HOST_BIND=0.0.0.0` é só para desenvolvimento.
- [ ] **A chave de API nunca vai para o navegador nem para o app de celular.** Ela vive só no **backend** do cliente (cofre de segredos). Quem tiver a chave age como o cliente
      (cota, cria remessa). Se vazar, o dono cria outra e **revoga** a vazada (`DELETE /portal/chaves/{id}`, seção 3.3). A tela do portal usa **login e cookie**, nunca a chave.
- [ ] **`ADMIN_API_TOKEN` forte e protegido:** gerado com `openssl rand -hex 32` (mínimo 32 caracteres), no Secrets Manager, usado só por ferramenta interna e
      nunca no navegador. Ele é **mais poderoso que uma chave de API** (cria clientes e donos). A rota `/api/v1/admin` também deve ficar só na rede interna. Se vazar, troque o valor e reinicie a app.
- [ ] **Sessão e cookie do portal:** `SESSION_DRIVER=redis`, `APP_ENV=production` (liga o cookie `Secure`) e a API atrás de **HTTPS**. Mantenha `SESSION_SAME_SITE=strict`,
      e o portal no **mesmo domínio registrável** do hub (seção 3.5). Com o portal em outro domínio: `CORS_ALLOWED_ORIGINS` com a origem exata e `CORS_SUPPORTS_CREDENTIALS=true`.
- [ ] **Senhas do dono:** troque a senha provisória no primeiro acesso e, se possível, crie um dono por cliente com e-mail corporativo.
- [ ] **Contas reais:** cada cliente cadastra as credenciais dele da Jadlog e dos Correios (por API ou `transportadora:configurar`); testar em homologação dos Correios.
      Confirmar com as transportadoras que o **remetente da remessa pode ser diferente do titular do contrato** (ex.: o cliente despacha em nome de outra loja).
- [ ] **Confirmar as premissas** das transportadoras, principalmente o formato da pré-postagem e da etiqueta dos Correios
      e o divisor de cubagem da Jadlog. Lista completa com exemplos: [`docs/premissas-das-transportadoras.md`](docs/premissas-das-transportadoras.md).
- [ ] **Jadlog não tem ambiente de teste:** no modo `real`, criar remessa cria pedido de verdade. Pedir um token de teste à Jadlog.
- [ ] **Etiquetas no S3:** trocar `ETIQUETAS_DISK` para um disco S3.
- [ ] **Redis gerenciado** (ex.: ElastiCache) e **Postgres gerenciado** com `DB_SSLMODE=require`.
- [ ] **Segredos no AWS Secrets Manager** (`APP_KEY`, `ADMIN_API_TOKEN`, senhas do banco e do Redis). Hoje ficam no `.env.docker`.
- [ ] **ECS + ALB:** a imagem é a mesma para a API e para o agendador. Configurar `TRUSTED_PROXIES` com o endereço do ALB
      e usar `/up` como health check.
- [ ] **`APP_ENV=production`, `APP_DEBUG=false` e `TRANSPORTADORAS_MODO=real`.**
- [ ] Rotina para **apagar histórico antigo** de chamadas às transportadoras (`RETENCAO_CHAMADAS_DIAS`, padrão 30 dias).
- [ ] Rodar `composer audit` no pipeline.

**Trocar o `APP_KEY` depois de já ter dados** (os segredos e dados pessoais são criptografados com ele):
coloque a chave antiga em `APP_PREVIOUS_KEYS` e a nova em `APP_KEY`. A API passa a ler dados das duas e grava com a nova.

---

## 14. Outros documentos

| Arquivo | O que tem |
|---|---|
| [`docs/fluxo-completo.md`](docs/fluxo-completo.md) | **Comece por aqui**: a jornada de uma compra (CEP → cotação → remessa → etiqueta → devolução) mostrando o código de cada passo, e o **passo a passo para implementar uma transportadora nova** |
| [`docs/guia-do-codigo.md`](docs/guia-do-codigo.md) | Referência por camadas: injeção de dependência, middlewares, Form Request, Service, Eloquent |
| [`docs/glossario.md`](docs/glossario.md) | Os nomes usados no código e o que cada um significa |
| [`docs/premissas-das-transportadoras.md`](docs/premissas-das-transportadoras.md) | O que os PDFs não explicam e o que supusemos |
| [`docs/transportadoras-notas.md`](docs/transportadoras-notas.md) | Resumo dos PDFs da Jadlog e dos Correios |
| [`docs/adr/0001-estrutura-simples.md`](docs/adr/0001-estrutura-simples.md) | Por que o projeto é um Laravel simples e como o hub separa os clientes (multi-tenant) |
| [`docs/referencias/`](docs/referencias) | Os PDFs originais das transportadoras |
| [`docs/postman/`](docs/postman) | Collection do Postman (administração, portal e API) |
