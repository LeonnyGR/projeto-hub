# Glossário

Nomes do domínio em português, sem acento, no código, nas tabelas e na API. Termos técnicos em inglês
(`Service`, `Controller`, `Request`, `Resource`, `get`, `create`...).

A coluna "Não usar" mostra os nomes que as transportadoras usam. Eles só podem aparecer dentro de
`app/Transportadoras/Jadlog.php` e `app/Transportadoras/Correios.php`.

| Termo | O que é | Não usar |
|---|---|---|
| `Seller` | Lojista que vende no B2C. **Nao chama a API, nao tem chave e nao tem contrato proprio**: usa o contrato do B2C. Aparece na remessa como remetente. Todo dado (cotacao, remessa...) pertence a um seller, que vai na URL: `/sellers/{id}/...`. | cliente, embarcador, tenant |
| `ClienteApi` | O sistema que chama a API (hoje, o B2C). Tem a chave de API, o contrato com as transportadoras e os sellers que cadastrou. Quando houver outro sistema, vira outro `ClienteApi`. | usuario, consumidor |
| `ChaveApi` | Chave do `ClienteApi`: `prefixo.segredo`, enviada no header `Authorization: Bearer`. | token (reservado para o token das transportadoras), api key do seller |
| `codigo_externo` | Id do seller no B2C. Evita cadastrar o mesmo lojista duas vezes. | external_id |
| `Transportadora` | Jadlog ou Correios (`jadlog`, `correios`). | carrier, operador |
| `CredencialTransportadora` | Contrato de um seller com uma transportadora (token, cartão de postagem). | conta, login |
| `Cotacao` | Simulação de frete. Guardada por 15 minutos. | simulacao, orcamento |
| `Servico` | Uma opção de entrega da transportadora (ex.: SEDEX, .Package). Campo `servico_codigo`. | modalidade (Jadlog), produto (Correios) |
| `Remessa` | Um envio, criado a partir de uma cotação. `tipo = envio`. | pedido (Jadlog), pre-postagem (Correios) |
| Devolução | Remessa no sentido contrário. É uma `Remessa` com `tipo = devolucao` e `remessa_origem_id`. | reversa |
| `Volume` | Cada pacote físico, com peso (gramas) e medidas (cm). | caixa, objeto (Correios) |
| `Etiqueta` | PDF para colar no volume. Fica no Storage, não no banco. | rotulo |
| `codigo_rastreio` | Código que o destinatário usa para acompanhar. | tracking, codObjeto, shipmentId, cte |
| `id_externo` | Identificador da remessa dentro da transportadora (usado para cancelar). | external_id |
| `EventoRastreio` | Um fato do rastreio: status, descrição, local e quando ocorreu. | ocorrencia, scan |
| `ChamadaTransportadora` | Registro de uma chamada HTTP à transportadora, com dados sensíveis mascarados. | log_api |
| `Idempotency-Key` | Header que impede criar a mesma remessa duas vezes se o seller repetir a chamada. | |
| `request_id` | Número que identifica uma requisição. Aparece na resposta e nos logs. | trace_id |

## Convenções

- **Dinheiro** sempre em centavos (inteiro), com sufixo `_centavos`. Nunca float.
- **Peso** sempre em gramas (`peso_gramas`). Quem converte para kg é a classe da transportadora.
- **Prazo** em dias (`prazo_dias`).
- Todos os ids são UUID.
- Tabelas e colunas em `snake_case`, português, sem acento. Exceção: `idempotency_key` (nome do header).

## Status da remessa

| Status | Significa |
|---|---|
| `criando` | Registrada no nosso banco, ainda falando com a transportadora (dura milissegundos) |
| `criada` | A transportadora aceitou. Já tem `codigo_rastreio` |
| `despachada` | O seller avisou que entregou o pacote à transportadora |
| `em_transito` | Pacote a caminho |
| `saiu_para_entrega` | Em rota de entrega |
| `entregue` | Entregue ao destinatário |
| `cancelada` | Cancelada antes da postagem |
