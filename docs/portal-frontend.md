# Integração do portal (React) com a API do Frete Hub

Guia para quem vai construir o portal web. Tudo que o portal usa fica em `/api/v1/portal/...`.
A collection do Postman (`docs/postman/frete-hub.postman_collection.json`) tem exemplos de cada chamada; use a pasta **"Portal (dono e operadores)"** e ignore as pastas `0 - Administracao` e `1` a `8` (essas são para o backend de outros sistemas, que usa chave de API; o portal **não** usa chave de API).

## 1. Conceitos

- **Cliente** (tenant): a empresa. Cada usuário pertence a um cliente e só enxerga os dados dele.
- **Usuário**, com dois papéis (`papel` na resposta):
  - `adm_tenant` (dono): acesso total, incluindo transportadoras, chaves de API e operadores.
  - `operador_tenant`: só o dia a dia (cotar, remessas, devoluções, etiquetas). Nas telas de dono, a API responde **403**; esconda esses menus para operador.
- **Remessa** = envio. **Devolução** = remessa no sentido contrário de um envio já entregue.

## 2. Autenticação: cookie de sessão

O login cria uma sessão no servidor e devolve um cookie **HttpOnly** (o JavaScript não lê o cookie, e não deve tentar). **Não há token para guardar** (nada de localStorage/JWT). Em toda chamada o navegador só precisa enviar o cookie:

```ts
// fetch
fetch(url, { credentials: 'include', headers: { Accept: 'application/json' } });
// axios
axios.create({ baseURL, withCredentials: true, headers: { Accept: 'application/json' } });
```

Pontos importantes:

| Tema | Detalhe |
|---|---|
| CSRF | As rotas do portal **não usam token CSRF**. A proteção é o cookie `SameSite=Strict` + CORS com origem fixa. Não precisa de `/sanctum/csrf-cookie` nem de `X-XSRF-TOKEN`. |
| Mesmo site | Como o cookie é `SameSite=Strict`, o portal e a API precisam estar no **mesmo site** (mesmo domínio registrável), ex.: `portal.empresa.com.br` e `api.empresa.com.br`. Domínios totalmente diferentes (ou `localhost` apontando para uma API remota) não vão enviar o cookie. |
| CORS | Se o portal estiver em outra origem, o backend precisa de `CORS_ALLOWED_ORIGINS=https://portal.empresa.com.br` (origem exata, sem `*`) e `CORS_SUPPORTS_CREDENTIALS=true`. Headers aceitos: `Authorization`, `Content-Type`, `Idempotency-Key`, `Accept`. Expostos para leitura: `X-Request-Id`, `Retry-After`. Peça ao time do backend para liberar a origem de dev e a de produção. |
| Desenvolvimento local | O jeito mais simples é usar o proxy do Vite/CRA (`/api` → API). Assim o navegador enxerga tudo como mesma origem e não há CORS nem problema de cookie. |
| HTTPS | Em produção o cookie é `Secure`: só funciona em HTTPS. |
| Duração | Sessão de 120 min (`SESSION_LIFETIME`). Se expirar, a API responde 401. |
| Sempre JSON | Mande `Accept: application/json` e `Content-Type: application/json` nos POST/PUT/PATCH. |

### Fluxo recomendado

1. Ao abrir o app, chame `GET /portal/eu`. **200** = logado (guarde usuário + cliente no estado global). **401** = mostre a tela de login.
2. Login: `POST /portal/login` com `{ "email", "password" }`. A resposta é o usuário (sem os dados do cliente), então chame `GET /portal/eu` em seguida para pegar o cliente.
3. Qualquer resposta **401** em outra chamada = sessão caiu: limpe o estado e vá para o login.
4. Logout: `POST /portal/logout` (204). Limpe o estado local.

O usuário bloqueado ou de cliente desabilitado perde o acesso na hora (401 mesmo com a sessão aberta).

## 3. Formato das respostas

- Sucesso: `{ "data": ... }`. Listas de remessas e devoluções também trazem `links` e `meta` (paginação padrão do Laravel, 50 por página, `?page=2`).
- 204 (sem corpo): logout e troca de senha.
- Erro, **sempre** neste formato:

```json
{
  "erro": {
    "codigo": "validacao_falhou",
    "mensagem": "Os dados informados sao invalidos.",
    "detalhes": { "email": ["O campo email é obrigatório."] }
  },
  "request_id": "..."
}
```

`detalhes` só aparece quando há algo a detalhar. Em 422 de validação, `detalhes` é `{ campo: [mensagens] }` e os campos aninhados vêm como `volumes.0.peso_gramas`, `remetente.endereco.cep`. Use isso para marcar o erro no campo certo do formulário. **Mostre o `request_id` em erros 500/502** para o usuário poder passar ao suporte.

| Status | `erro.codigo` | O que fazer no front |
|---|---|---|
| 401 | `nao_autenticado` | No login: "e-mail ou senha inválidos" (a API não diz qual). Nas demais: voltar ao login. |
| 403 | `proibido` | Operador acessando rota de dono. |
| 404 | `nao_encontrado` e outros específicos | Recurso não existe ou é de outro cliente. |
| 409 | `em_processamento`, `devolucao_ja_existe` | Ver seção 5.5. |
| 422 | `validacao_falhou` e erros de negócio | Mostrar `mensagem` / marcar campos. |
| 429 | `muitas_requisicoes` | Ler o header `Retry-After` (segundos) e avisar o usuário. |
| 502 | `transportadora_indisponivel`, `servico_de_cep_indisponivel` | "Tente novamente"; permitir repetir. |
| 5xx | `erro_interno` | Mensagem genérica + `request_id`. |

Dica: crie um único cliente HTTP com interceptor que (a) trata o 401 globalmente e (b) converte o corpo de erro em uma classe `ApiError { status, codigo, mensagem, detalhes, requestId }`.

### Valores e datas

- Dinheiro sempre em **centavos inteiros** (`valor_centavos`, `valor_frete_centavos`, `valor_declarado_centavos`). Converta só na exibição.
- Datas em ISO 8601 (`criada_em`, `expira_em`...).
- Peso em gramas; dimensões em cm.
- CEP, CPF/CNPJ e telefone podem ser enviados com máscara; a API guarda só os números. UF é aceita em minúsculas.
- Todos os ids são UUID.

## 4. Rotas do portal

Prefixo: `/api/v1/portal`. Cada rota exige uma **permissão** (aplicação + ação, ver 4.7). O dono (`adm_tenant`) pode tudo; o operador só o que foi liberado (senão **403 `sem_permissao`**). 🔒 = só dono, sempre.

### 4.1 Sessão

| Método e rota | Corpo | Resposta |
|---|---|---|
| `POST /login` | `{ email, password }` | 200 `{ data: Usuario }` |
| `GET /eu` | — | 200 `{ data: Usuario + cliente: { id, nome, cnpj } + menu: Modulo[] }`. O `menu` já vem filtrado (ver 4.7). |
| `PATCH /eu/senha` | `{ senha_atual, nova_senha }` (mín. 10 caracteres, diferente da atual) | 204. Senha atual errada = 401. |
| `POST /logout` | — | 204 |

Limite do login: **5 tentativas por minuto** por e-mail + IP (429 depois disso). `Retry-After` diz quando tentar de novo.

```ts
type Usuario = {
  id: string; nome: string; email: string;
  papel: 'adm_tenant' | 'operador_tenant';
  ativo: boolean; ultimo_login_em: string | null;
  acesso_total: boolean;                                   // true = dono (pode tudo)
  permissoes?: { aplicacao: string; acoes: string[] }[];   // operador; só vem em GET/POST /usuarios
};
```

### 4.2 Conta

`GET /conta` → `{ data: { id, nome, cnpj, ativo } }`

### 4.3 Dia a dia (dono e operador)

**Consultas auxiliares**

- `GET /enderecos/{cep}` → endereço do ViaCEP (`data`). 422 `cep_invalido`, 404 `cep_nao_encontrado`. Use para autopreencher o formulário de endereço.
- `GET /pontos-retirada?cep=...&tipos=LOCKER,PUBLICO&transportadora=jadlog` → `{ data: [pontos], falhas: [...] }`. Só `cep` é obrigatório.

**Cotação**

- `POST /cotacoes`
  ```json
  {
    "cep_origem": "01001000",
    "cep_destino": "20010000",
    "valor_declarado_centavos": 15000,
    "volumes": [{ "peso_gramas": 1200, "altura_cm": 10, "largura_cm": 20, "comprimento_cm": 30 }]
  }
  ```
  Limites: até 10 volumes; peso até 30.000 g; cada dimensão até 105 cm; valor declarado até R$ 100.000,00 (10.000.000 centavos). Todos os campos são obrigatórios, inclusive `cep_origem`.
  Resposta **201**:
  ```json
  { "data": {
      "id": "uuid", "cep_origem": "...", "cep_destino": "...",
      "expira_em": "2026-10-09T12:15:00-03:00",
      "opcoes": [ { "transportadora": "jadlog", "servico_codigo": "3", "servico_nome": "Package", "valor_centavos": 2490, "prazo_dias": 3 } ],
      "falhas": [ { "transportadora": "correios", "motivo": "sem_cobertura" } ]
  } }
  ```
  `opcoes` já vem ordenada (mais barata primeiro). `falhas` = transportadoras que não responderam: **não é erro**, mostre um aviso discreto ("Correios não respondeu") e siga com as opções. **A cotação vale 15 minutos**: mostre a contagem regressiva a partir de `expira_em`.
- `GET /cotacoes/{id}` → mesma estrutura.

**Remessas**

- `GET /remessas?status=criada&page=1` — lista paginada (50), mais novas primeiro.
- `POST /remessas` — **exige o header `Idempotency-Key`** (8 a 128 caracteres: letras, números, `.`, `_`, `-`). Corpo:
  ```json
  {
    "cotacao_id": "uuid",
    "transportadora": "jadlog",
    "servico_codigo": "3",
    "numero_pedido": "PED-1001",
    "conteudo": "Roupas",
    "ponto_retirada_id": "opcional",
    "documento_fiscal": { "tipo": "nfe", "chave": "44 digitos", "numero": "123", "serie": "1", "cfop": "5102" },
    "remetente":    { "nome", "documento", "telefone", "email", "endereco": { "cep", "logradouro", "numero", "complemento", "bairro", "cidade", "uf" } },
    "destinatario": { "...mesma estrutura..." }
  }
  ```
  `transportadora` + `servico_codigo` vêm de **uma das `opcoes` da cotação escolhida**. Os CEPs de remetente e destinatário precisam ser os mesmos da cotação. `documento_fiscal` é opcional (sem ele vai uma declaração de conteúdo); `tipo` ∈ `declaracao|nf|nfe|cte|dce`, e os demais campos são exigidos conforme o tipo (a resposta 422 diz quais). Obrigatórios em remetente/destinatário: nome (2–60), documento (CPF/CNPJ válido), telefone (10–11 dígitos), endereço completo (complemento opcional). E-mail é opcional.
  Resposta **201** com a remessa; se repetir a mesma chamada com a mesma `Idempotency-Key`, volta **200** com a remessa original e o header `Idempotent-Replayed: true`.
- `GET /remessas/{id}`
- `POST /remessas/{id}/despachar` — marca que o pacote foi entregue à transportadora. Só com status `criada`.
- `POST /remessas/{id}/cancelar` — só com status `criada` ou `despachada`.
- `GET /remessas/{id}/etiqueta` — **PDF** (ver 5.2).

**Devoluções**

- `GET /devolucoes?status=...`
- `POST /devolucoes` (também exige `Idempotency-Key`): `{ "remessa_id": "uuid", "motivo": "texto 3–200" }`. Só vale para remessa **entregue**: senão 422 `remessa_nao_entregue`. Se já existe devolução em andamento, 409 `devolucao_ja_existe`.
- `GET /devolucoes/{id}`
- `GET /devolucoes/{id}/etiqueta` — PDF.

**Objeto remessa (envio e devolução têm o mesmo formato)**

```ts
type Remessa = {
  id: string;
  tipo: 'envio' | 'devolucao';
  status: StatusRemessa;
  transportadora: string; servico_codigo: string;
  valor_frete_centavos: number | null; prazo_dias: number | null;
  codigo_rastreio: string | null;
  cotacao_id?: string;                              // só em envio
  remessa_origem_id?: string; motivo?: string;      // só em devolução
  remetente: Pessoa; destinatario: Pessoa;
  volumes: { peso_gramas; altura_cm; largura_cm; comprimento_cm; codigo_rastreio }[];
  etiqueta_disponivel: boolean;
  despachada_em: string | null; cancelada_em: string | null; criada_em: string;
};
type StatusRemessa = 'criando' | 'criada' | 'despachada' | 'em_transito' | 'saiu_para_entrega' | 'entregue' | 'cancelada';
```

Ciclo de vida: `criando → criada → despachada → em_transito → saiu_para_entrega → entregue`, com `cancelada` possível a partir de `criada` ou `despachada`. Use isso para habilitar os botões: **Despachar** só em `criada`; **Cancelar** em `criada`/`despachada`; **Criar devolução** só em `entregue`. O status `em_transito` em diante é atualizado pelo servidor, então use polling ou um botão "atualizar" nas telas de acompanhamento.

### 4.4 Transportadoras (`TRA_01`: `ver` lê, `editar` cadastra/edita; mostrar credenciais 🔒 é só do dono)

- `GET /transportadoras/catalogo` — descreve os formulários de cadastro. **Monte o formulário a partir dele** em vez de fixar os campos no front:
  ```json
  { "data": [ { "codigo": "jadlog", "nome": "Jadlog", "campos": [
      { "campo": "credenciais.token", "grupo": "credenciais", "titulo": "Token da Jadlog", "tipo": "secreto", "obrigatorio": true, "padrao": null, "ajuda": null, "opcoes": null },
      { "campo": "configuracao.tipo_coleta", "grupo": "configuracao", "titulo": "Tipo de coleta", "tipo": "select", "padrao": "K", "opcoes": { "K": "A Jadlog coleta no lojista", "S": "..." } },
      { "campo": "configuracao.servicos", "tipo": "servicos", "ajuda": "Lista de {codigo, nome}..." }
  ] } ] }
  ```
  `campo` é o caminho no JSON enviado (`configuracao.tomador.endereco.cep` → `{ configuracao: { tomador: { endereco: { cep } } } }`). Tipos: `texto`, `secreto` (input password), `select` (usa `opcoes`, mapa valor → rótulo), `servicos` (lista editável de `{codigo, nome}`).
- `GET /transportadoras` — as cadastradas pelo cliente. `GET /transportadoras/{codigo}` — uma só (404 `transportadora_nao_cadastrada` se ainda não existe). Os segredos voltam **mascarados** (`credenciais_mascaradas`, ex.: `••••••1234`).
  ```ts
  type TransportadoraCliente = { transportadora: string; ativa: boolean; credenciais_cadastradas: true;
    credenciais_mascaradas: Record<string,string>; configuracao: object; atualizado_em: string };
  ```
- `PUT /transportadoras/{codigo}` 🔒 — cadastra (**201**) ou edita (**200**). Corpo: `{ credenciais: {...}, configuracao: {...}, ativa?: boolean }`. **Na edição, campo de credencial vazio ou ausente = manter o valor salvo.** Então no formulário de edição mostre o valor mascarado como placeholder, deixe o input vazio e envie só o que a pessoa digitou. No primeiro cadastro todas as credenciais são obrigatórias. Códigos válidos: `jadlog`, `correios`.
- `PATCH /transportadoras/{codigo}` 🔒 — `{ "ativa": true|false }`. Transportadora desativada não entra nas cotações, mas os dados ficam salvos.
- `POST /transportadoras/{codigo}/credenciais` 🔒 — "mostrar credenciais": `{ "password": "<senha do dono>" }` → `{ data: { transportadora, credenciais: {...} } }`. Abra um modal pedindo a senha. Erros: 422 `senha_incorreta`, 404 se não cadastrada, 429 se passar de 5 consultas por minuto. **Não guarde o resultado em estado global/localStorage**: mostre e descarte ao fechar o modal.

### 4.5 Chaves de API (`ADM_01`: `ver`, `criar`, `excluir`)

Chaves que o sistema do cliente (ex.: o backend do e-commerce) usa para chamar a API sem o portal.

- `GET /chaves` → lista `{ id, nome, prefixo, ultimo_uso_em, revogada_em, criada_em }`. O segredo nunca é listado.
- `POST /chaves` `{ "nome": "producao" }` (2–60 caracteres) → **201** com os mesmos campos **mais `chave`**, o texto completo da chave. **Ele só aparece nesta resposta, uma vez.** Mostre num modal com botão "copiar" e aviso claro de que não será exibido de novo.
- `DELETE /chaves/{id}` → revoga (quem usa passa a receber 401). Peça confirmação.

### 4.6 Usuários (`ADM_02`: `ver`, `criar`, `editar`)

- `GET /usuarios` → lista de `Usuario` (dono + operadores).
- `POST /usuarios` `{ nome (2–120), email, password (mín. 10), permissoes? }` → 201 com o operador. `permissoes` (opcional, formato de 4.7) já libera as aplicações na criação; sem ela o operador nasce **sem nada** e o menu dele vem vazio. E-mail único no sistema (422 em `detalhes.email` se já existe).
- `PATCH /usuarios/{id}` — qualquer combinação de `{ nome, ativo, password }`. `ativo: false` bloqueia na hora; `password` redefine a senha. Só atua em **operadores** (o dono ou usuário de outro cliente dá 404). O e-mail não muda.

### 4.7 Módulos, aplicações e permissões

O catálogo é do sistema (igual para todo cliente): **módulos** (grupos do menu) com **aplicações** (telas). Cada aplicação tem um `caminho` (a rota da página no front, **idêntica** ao path que o front implementa) e as **ações** que aceita. O menu **vem só do `GET /eu`**: desenhe a barra lateral por ele e não decida nada no front.

```ts
type Modulo = { codigo: string; nome: string; icone: string;      // icone: classe do Bootstrap Icons
  aplicacoes: { codigo: string; nome: string; caminho: string; acoes: string[] }[] };
```

| Módulo | Aplicação | Caminho | Ações |
|---|---|---|---|
| COT Cotação `bi bi-calculator` | COT_01 Cotação de frete | `cotacao/cotar` | criar |
| | COT_02 Lista de Cotações | `cotacao/listar` | ver |
| OPE Operação `bi bi-box-seam` | OPE_01 Nova Remessa | `operacao/remessa/criar` | criar |
| | OPE_02 Listar Remessas | `operacao/remessa/listar` | ver, etiqueta, despachar, cancelar |
| REV Reversa `bi bi-arrow-return-left` | REV_01 Listar Devoluções | `reversa/devolucao/listar` | ver, etiqueta |
| | REV_02 Criar Devolução | `reversa/devolucao/criar` | criar |
| TRA Transportadoras `bi bi-truck` | TRA_01 Minhas transportadoras | `transportadoras/listar` | ver, editar |
| ADM Administração `bi bi-gear` | ADM_01 Chaves de API | `administracao/chaves` | ver, criar, excluir |
| | ADM_02 Usuários | `administracao/usuarios` | ver, criar, editar |

No `GET /eu`, o `menu` traz só os módulos com alguma aplicação liberada e, em cada aplicação, as `acoes` que a pessoa tem (use para mostrar/esconder botões: "Cancelar", "Etiqueta"...). O dono recebe tudo (`acesso_total: true`). **Esconder no front não é segurança**: o servidor devolve **403 `sem_permissao`** (com `detalhes.aplicacao` e `detalhes.acao`) quando a ação não é permitida. A chave de API não é afetada por isso.

Mapa rota → permissão: `POST /cotacoes` COT_01 criar · `GET /cotacoes`, `GET /cotacoes/{id}` COT_02 ver · `POST /remessas` OPE_01 criar · `GET /remessas[/{id}]` OPE_02 ver · `…/etiqueta` OPE_02 etiqueta · `…/despachar` OPE_02 despachar · `…/cancelar` OPE_02 cancelar · `GET /devolucoes[/{id}]` REV_01 ver · `…/devolucoes/{id}/etiqueta` REV_01 etiqueta · `POST /devolucoes` REV_02 criar · transportadoras (leitura) TRA_01 ver, (PUT/PATCH) TRA_01 editar · chaves ADM_01 · usuários e permissões ADM_02. `GET /conta`, `GET /enderecos/{cep}` e `GET /pontos-retirada` só pedem estar logado.

**Tela de permissões do operador** (precisa de ADM_02):

- `GET /permissoes/catalogo` → `{ data: Modulo[] }` (sem filtro): monte a grade de checkboxes (aplicação × ação).
- `GET /usuarios/{id}/permissoes` → `{ data: { usuario_id, permissoes: [{ aplicacao, acoes }] } }`.
- `PUT /usuarios/{id}/permissoes` `{ permissoes: [{ aplicacao: "OPE_02", acoes: ["ver","etiqueta"] }] }` → mesmo formato. **Troca tudo**: o que não vier, o operador perde (lista vazia = nada). Erros: 422 `permissao_invalida` (aplicação/ação inexistente), 403 `permissao_acima_do_proprio` (só se concede o que se tem; o dono concede tudo). Só atua em **operadores** (dono ou outro cliente = 404).

Ações são independentes: `etiqueta` ou `cancelar` sem `ver` não abre a listagem. Ao marcar uma ação, marque `ver` junto na tela.

## 5. Receitas

### 5.1 Cliente HTTP base

```ts
export class ApiError extends Error {
  constructor(public status: number, public codigo: string, mensagem: string,
              public detalhes?: Record<string, string[]>, public requestId?: string) { super(mensagem); }
}

const BASE = '/api/v1/portal';

export async function api<T>(path: string, init: RequestInit & { idempotencyKey?: string } = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (init.body) headers['Content-Type'] = 'application/json';
  if (init.idempotencyKey) headers['Idempotency-Key'] = init.idempotencyKey;

  const res = await fetch(BASE + path, { ...init, headers: { ...headers, ...init.headers }, credentials: 'include' });
  if (res.status === 204) return undefined as T;

  const corpo = await res.json().catch(() => null);
  if (!res.ok) {
    if (res.status === 401 && path !== '/login') window.dispatchEvent(new Event('sessao-expirada'));
    const e = corpo?.erro;
    throw new ApiError(res.status, e?.codigo ?? 'erro', e?.mensagem ?? 'Erro inesperado', e?.detalhes, corpo?.request_id);
  }
  return corpo;
}
```

### 5.2 Etiqueta (PDF)

A rota exige o cookie, então não dá para usar um `<a href>` direto se a API estiver em outra origem. Baixe como blob:

```ts
async function abrirEtiqueta(remessaId: string) {
  const res = await fetch(`${BASE}/remessas/${remessaId}/etiqueta`, { credentials: 'include' });
  if (!res.ok) { /* 404 etiqueta_indisponivel etc.: o corpo é JSON de erro */ return; }
  const url = URL.createObjectURL(await res.blob());
  window.open(url, '_blank');
}
```

Em remessas dos Correios a etiqueta é gerada na primeira consulta, então pode demorar um pouco. Mostre um loading no botão.

### 5.3 Idempotency-Key ao criar remessa/devolução

Gere **um UUID por tentativa de "confirmar"** (`crypto.randomUUID()`) quando o usuário abrir o formulário/modal e **reutilize o mesmo** se ele clicar de novo ou se der timeout/erro de rede. Assim um duplo clique ou um retry não cria duas remessas. Gere uma chave nova só quando for um pedido diferente (outro formulário preenchido). Reusar a mesma chave com dados diferentes dá 422 `idempotency_key_reutilizada`. Desabilite o botão enquanto a chamada está em andamento.

### 5.4 Fluxo da tela "Nova remessa"

1. Formulário de cotação (CEPs, valor declarado, volumes) → `POST /cotacoes`.
2. Lista as `opcoes` ordenadas (preço, prazo, transportadora). Avisos para `falhas`. Contador de `expira_em`.
3. Usuário escolhe uma opção e preenche remetente, destinatário e (opcional) nota fiscal. Dê para pré-preencher o endereço com `GET /enderecos/{cep}`.
4. `POST /remessas` com a `Idempotency-Key`. Em sucesso, vá para o detalhe, com botão de etiqueta.
5. Se vier 422 `cotacao_expirada`, volte ao passo 1 mantendo os dados já digitados.

### 5.5 Erros de negócio úteis

`cotacao_expirada`, `cotacao_nao_encontrada`, `opcao_invalida`, `cep_diferente_da_cotacao`, `remessa_recusada`, `cancelamento_recusado`, `remessa_nao_entregue`, `devolucao_ja_existe`, `em_processamento` (409: a remessa ainda está sendo criada, tente de novo em instantes), `etiqueta_indisponivel`, `sem_transportadoras` (o cliente ainda não cadastrou nenhuma: leve o dono à tela de transportadoras), `transportadora_nao_cadastrada`, `senha_incorreta`. Todos trazem `mensagem` em português pronta para exibir.

## 6. Limites de requisição

Por cliente, por minuto (valores padrão; 429 ao exceder, com `Retry-After`): cotações 120, remessas/devoluções (criar, despachar, cancelar) 30, consultas 300, cadastros (conta, transportadoras, chaves, usuários) 60, login 5, "mostrar credenciais" 5. Evite disparar uma requisição a cada tecla (use debounce no autocompletar de CEP) e não faça polling agressivo.

## 7. Checklist de segurança no front

- Nunca armazenar senha, chave de API ou credenciais de transportadora em `localStorage`, estado persistido ou logs.
- A chave de API recém-criada e as credenciais reveladas só vivem no estado do modal.
- Esconda telas e botões de dono para operador, **mas a API é a barreira real** (403).
- Trate 401 globalmente e limpe o estado do usuário.
- Em desenvolvimento, aponte para o ambiente de testes: os CEPs `22222222`, `33333333`, `44444444`, `55555555` e `66666666` simulam, nesta ordem, sem cobertura, timeout, erro 500 da transportadora, resposta quebrada e credencial recusada. Servem para testar as telas de erro e de `falhas`.
