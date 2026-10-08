# ADR 0001: Laravel simples, sem módulos

- Status: aceito (substitui a versão modular de 06/10/2026)
- Data: 07/10/2026

## Contexto

A API de frete (Jadlog e Correios) vai ser mantida por devs plenos e juniores. Hoje são poucos clientes, mas a
lista pode crescer. O código da intranet (Laravel padrão: Controller, Model, Request) é a referência de nível.

A primeira versão do projeto usava módulos, um ServiceProvider por módulo, DTOs imutáveis, Mappers,
global scope por cliente e circuit breaker. Era muita estrutura para o que a API faz.

## Decisão

1. **Estrutura padrão do Laravel**, sem módulos:
   `Http/Controllers`, `Http/Requests`, `Http/Resources`, `Models`, `Services`, `Transportadoras`.
2. **Controller fino**: valida (Form Request), chama um Service e devolve um Resource. Regra de negócio fica no Service.
3. **Isolamento entre clientes explícito**: toda consulta parte do cliente logado
   (`$cliente->remessas()->findOrFail($id)`). Se o recurso é de outro cliente, o resultado é 404.
   Não usamos global scope: quem lê o código vê o filtro.
4. **Uma classe por transportadora** em `app/Transportadoras/` (`Jadlog.php`, `Correios.php`), com os mesmos
   métodos definidos em uma interface pequena. Só essas classes conhecem URLs, campos e códigos de erro de cada transportadora.
   Para adicionar outra: copiar uma classe e registrar o nome em `Transportadoras.php`.
5. **Envio e devolução na mesma tabela** (`remessas`, coluna `tipo`): a devolução é uma remessa no sentido contrário.
6. **Sem fila e sem webhook**: as transportadoras dos PDFs não têm webhook, então o rastreio é atualizado por um
   comando agendado. A criação da remessa é feita na própria requisição.
7. Redis para cache (token dos Correios), limite de requisições e sessões do portal.

## Multi-tenant: quem é o cliente (decisão de 08/10/2026)

O hub atende vários **clientes** (lojistas, B2C...). Cada cliente é um "tenant": tem o próprio contrato com as transportadoras
e só enxerga os próprios dados. Por isso:

- **Três portas de entrada, cada uma com a sua autenticação** (decisão de 08/10/2026, substitui o HTTP Basic `client_id`/`client_secret` que usamos antes):
  1. **Administração** (`/api/v1/admin/...`): só nós, com `Authorization: Bearer <ADMIN_API_TOKEN>` (token do `.env`). Cria o cliente **e o usuário dono** dele.
     Não usamos comando artisan porque ninguém deve precisar de acesso ao servidor para cadastrar cliente. Token vazio ou com menos de 32 caracteres desliga a administração.
  2. **Chave de API** (`/api/v1/...`): para **máquina** (o backend do cliente). `Authorization: Bearer fs_<prefixo>.<segredo>`. O prefixo (público) acha a chave no banco e, por ela, o cliente;
     o segredo é conferido por hash sha256. Um cliente tem várias chaves (`producao`, `homologacao`), cada uma revogável sozinha, o que permite **trocar a chave sem parada**
     (cria a nova, atualiza o backend, revoga a antiga). O texto completo aparece uma única vez, ao criar.
  3. **Portal** (`/api/v1/portal/...`): para **pessoas** (dono e operadores do cliente). Login com e-mail e senha; o hub guarda a sessão no Redis e entrega um cookie
     `HttpOnly` + `SameSite=Strict` (e `Secure` em produção). Papéis: `adm_tenant` (dono: tudo, inclusive chaves de API, operadores e transportadoras) e `operador_tenant` (só o dia a dia).
  As portas 2 e 3 usam **as mesmas rotas de negócio**, declaradas uma vez; muda só quem autentica. Cliente desabilitado ou usuário bloqueado perde o acesso na hora (a checagem de `ativo` é feita em cada chamada).
- **Por que dois mecanismos (chave de API e login)?** Máquina e pessoa têm necessidades diferentes. A máquina precisa de uma credencial longa, aleatória, sem cookie, que dê para revogar e rotacionar.
  A pessoa precisa de senha, de papéis diferentes (dono x operador) e de uma sessão que o servidor possa derrubar. Misturar os dois (por exemplo, o dono usar a chave de API no navegador) colocaria um segredo de máquina em lugar inseguro.
- **Por que não JWT nem OAuth2 agora?**
  - **JWT:** o token vale até expirar e o servidor não consegue derrubá-lo na hora (bloquear um usuário ou revogar uma chave exigiria uma lista de bloqueio, ou seja, voltar a ter estado no servidor).
    Com sessão no Redis e chave no banco, revogar é apagar/marcar uma linha. Para o portal, o cookie `HttpOnly` também é mais seguro que um JWT guardado no navegador (que um script injetado conseguiria ler).
  - **OAuth2:** resolve "um terceiro acessa os dados de um usuário sem saber a senha dele" ou login único entre sistemas. Não temos esse problema: só há os nossos clientes e as nossas telas.
    Implementar servidor de autorização, escopos e refresh token seria muita peça para o que precisamos hoje.
  - **Evolução possível:** se algum cliente exigir OAuth2 `client_credentials`, dá para acrescentar um endpoint `/token` que troque a chave de API por um token de curta duração, sem mudar as rotas de negócio.
- **Nós (administradores) cadastramos o cliente pela API de administração**; o dono entra no portal, cria as chaves de API e os operadores. Não há mais "trocar secret" do cliente: troca-se a **chave**.
  O cliente nunca usa o token de admin.
  (Nota histórica: antes isso era feito pelos comandos `cliente:create` e afins, já removidos, e depois por `client_id` + `client_secret` em HTTP Basic, também removido.)
- **O contrato com as transportadoras é de cada cliente** (tabela `credenciais_transportadoras`, com `cliente_id`). O cliente cadastra os
  dados dele (token, cartão de postagem, serviços contratados, tomador) por `PUT /transportadoras/{t}`, normalmente por uma tela que consome a API.
  Os dados ficam criptografados e nunca voltam nas respostas. Quem paga o frete (tomador) é o titular do contrato, ou seja, o cliente.
- **O catálogo de campos mora no código** (`perguntas()`, `regras()` e `nome()` de cada classe de transportadora), não numa tabela.
  O hub só atende uma transportadora que tem classe de integração, então uma tabela só poderia ficar fora de sincronia com o código. O portal monta o formulário
  a partir de `GET /transportadoras/catalogo`; adicionar uma transportadora é criar a classe e registrá-la em `Transportadoras::LISTA`.
- **As credenciais ficam criptografadas, mas o dono pode vê-las por inteiro**, com a senha dele e registro em log. Motivo: o cliente cadastrou o dado
  e precisa conferi-lo (um token digitado errado é o erro mais comum). A listagem traz só a **máscara** (`••••••1234`). Para ver por inteiro:
  só pelo portal e só o dono, **pede a senha de novo** (uma sessão esquecida aberta não basta), tem limite (`LIMITE_REVELAR_MIN`, 5 por minuto) e cada consulta
  vai para o log (quem, qual cliente, qual transportadora; nunca o segredo). A porta da chave de API não tem essa rota. Já as chaves `fs_...` do hub continuam
  aparecendo por inteiro só na criação.
- **As rotas são planas** (`/api/v1/cotacoes`, `/api/v1/remessas`...): o cliente vem da autenticação, não da URL.
  O CEP de origem não fica no cadastro: o B2C informa o `cep_origem` em cada cotação.
- **A chave de API nunca pode ir para o navegador nem para o app de celular**: só o backend do cliente a guarda. A tela do portal usa login e cookie.
  O `ADMIN_API_TOKEN` é ainda mais poderoso (cria clientes): só ferramenta interna ou Secrets Manager.
  Em produção as portas 1 e 2 devem ficar inacessíveis de fora (mesma rede/VPC, security group ou lista de IPs).
- **Cookie do portal e CSRF:** o cookie é `SameSite=Strict` e a API só aceita JSON; por isso **não há token CSRF**. Isso exige que o portal seja "same-site" com o hub
  (mesmo domínio registrável, ex.: `portal.bartofil.com.br` e `hub.bartofil.com.br`). Se um dia o portal ficar **cross-site**, essa decisão precisa ser revista.
- **Mesmo erro para qualquer falha de login** (401), e limite de 5 tentativas por minuto por e-mail + IP, para não revelar quais e-mails existem nem permitir adivinhar senha.
- Nota histórica: a primeira versão tinha "sellers" na URL (`/sellers/{id}/...`) e um único contrato do B2C valendo para todos.
  Isso foi substituído pelo modelo acima; os sellers viraram clientes.

## Consequências

- (+) Menos arquivos e menos conceitos. Um dev novo acha tudo pelo nome da funcionalidade.
- (+) Adicionar um cliente é uma chamada `POST /admin/clientes`; o dono cria as chaves, os operadores e cadastra os contratos; adicionar uma transportadora é uma classe nova.
- (−) O dono consegue ver os segredos que cadastrou: quem roubar a senha dele (e passar do limite de consultas) os vê. O log e a senha extra reduzem o risco, não o eliminam.
- (−) Sessão em Redis: se o Redis cair, ninguém consegue logar no portal (a API por chave de API continua funcionando, pois a chave está no banco).
- (−) Sem o "aviso" automático ao cliente quando o status muda (ele consulta o rastreio). Se isso virar necessidade,
  entra depois como callback para uma URL do cliente.
- (−) Sem circuit breaker: se uma transportadora cair, cada cotação espera o timeout (3 s) dela. Se isso incomodar, adicionamos depois.
