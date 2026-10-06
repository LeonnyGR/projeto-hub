# ADR 0001: Laravel simples, sem módulos

- Status: aceito (substitui a versão modular de 06/10/2026)
- Data: 07/10/2026

## Contexto

A API de frete (Jadlog e Correios) vai ser mantida por devs plenos e juniores. Hoje são 2 sellers, mas a
lista pode crescer. O código da intranet (Laravel padrão: Controller, Model, Request) é a referência de nível.

A primeira versão do projeto usava módulos, um ServiceProvider por módulo, DTOs imutáveis, Mappers,
global scope por seller e circuit breaker. Era muita estrutura para o que a API faz.

## Decisão

1. **Estrutura padrão do Laravel**, sem módulos:
   `Http/Controllers`, `Http/Requests`, `Http/Resources`, `Models`, `Services`, `Transportadoras`.
2. **Controller fino**: valida (Form Request), chama um Service e devolve um Resource. Regra de negócio fica no Service.
3. **Isolamento entre sellers explícito**: toda consulta parte do seller logado
   (`$seller->remessas()->findOrFail($id)`). Se o recurso é de outro seller, o resultado é 404.
   Não usamos global scope: quem lê o código vê o filtro.
4. **Uma classe por transportadora** em `app/Transportadoras/` (`Jadlog.php`, `Correios.php`), com os mesmos
   métodos definidos em uma interface pequena. Só essas classes conhecem URLs, campos e códigos de erro de cada transportadora.
   Para adicionar outra: copiar uma classe e registrar o nome em `Transportadoras.php`.
5. **Envio e devolução na mesma tabela** (`remessas`, coluna `tipo`): a devolução é uma remessa no sentido contrário.
6. **Sem fila e sem webhook**: as transportadoras dos PDFs não têm webhook, então o rastreio é atualizado por um
   comando agendado. A criação da remessa é feita na própria requisição.
7. Redis só para cache (token dos Correios) e limite de requisições.

## Quem chama a API e quem é o seller (decisão de 07/10/2026)

No fluxo real, o cliente final escolhe o produto de um seller **no B2C**, e é o backend do B2C que chama esta API.
Por isso:

- **Quem autentica é o B2C** (o "cliente da API"), com uma chave no header `Authorization`. Sellers não têm chave
  (a primeira versão tratava cada seller como um cliente da API, e isso foi um erro de entendimento).
- **O seller vem na URL** (`/api/v1/sellers/{seller_id}/cotacoes`). O B2C guarda o `seller_id` de cada lojista.
- **O contrato com as transportadoras é do B2C** (a Bartofil assina com a Jadlog e os Correios), e não de cada seller.
  O sistema guarda, criptografados, os dados de acesso que as transportadoras entregaram (token, cartão de postagem, tomador do frete),
  uma vez, e eles valem para todos os sellers do cliente. O seller entra como **remetente** da remessa; quem paga o frete (tomador)
  é sempre o titular do contrato.
- **Essas credenciais só entram por comando** (`transportadora:configurar`, rodado pela TI), nunca por API. São dados sensíveis,
  mudam raramente, e a chave de API usada todo dia pelo checkout não deve poder trocá-los. A API só permite consultar (sem segredos).
- **Sellers são cadastrados por API** (`POST /sellers`); cada seller pertence ao cliente que o cadastrou.
  Só a chave do B2C é criada por comando (`cliente:create`), porque é preciso uma chave para chamar a API pela primeira vez.
- O isolamento entre sellers continua (`$seller->remessas()->findOrFail($id)`), mas agora protege contra engano do B2C
  (pedir a remessa de um seller na URL de outro dá 404), e não contra um estranho.
- A chave **nunca** pode ir para o navegador ou app do cliente final: só o backend do B2C a conhece. Em produção a API
  também deve ficar inacessível de fora (mesma rede/VPC, security group ou lista de IPs).

## Consequências

- (+) Menos arquivos e menos conceitos. Um dev novo acha tudo pelo nome da funcionalidade.
- (+) Adicionar um seller é só chamar `POST /sellers` e cadastrar os contratos por API; adicionar uma transportadora é uma classe nova.
- (−) Sem o "aviso" automático ao seller quando o status muda (ele consulta o rastreio). Se isso virar necessidade,
  entra depois como callback para uma URL do seller.
- (−) Sem circuit breaker: se uma transportadora cair, cada cotação espera o timeout (3 s) dela. Se isso incomodar, adicionamos depois.
