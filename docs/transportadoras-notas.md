# Notas de leitura dos PDFs das transportadoras

Fontes (em `docs/referencias/`): `api_integracao_jad_log.pdf` (Jadlog, v2.3, ago/2025) e
`manual-para-integracao-correios-api.pdf` (Correios, v2.4, abr/2025). Os PDFs são a fonte da verdade;
tudo que **não** está neles aparece como **PREMISSA** e deve ser validado com a transportadora.
Partes do PDF que são imagem (tabela de campos da Jadlog p.9; telas do Postman dos Correios) foram lidas renderizando as páginas.

## Jadlog

**Autenticação**: header `Authorization: <token>` em toda chamada (token fornecido pela Jadlog; vem junto com CNPJ do usuário,
código do cliente, conta corrente — se correntista — e código da franquia). Sem fluxo de login/renovação.
`Content-Type: application/json`.

| Operação | Método e URL | Observações |
|---|---|---|
| Simular frete | `POST https://www.jadlog.com.br/embarcador/api/frete/valor` | `{"frete":[{cepori,cepdes,frap,peso(kg, maior entre real e cubado),cnpj,conta,contrato,modalidade,tpentrega(D/R),tpseguro(N/A),vldeclarado,vlcoleta}]}` — **1 a 3 itens** por chamada (um por modalidade). Resposta: `{"frete":[{..., "prazo":5, "vltotal":7.50}]}` (valores em reais, `double`). |
| Incluir pedido (remessa) | `POST https://www.jadlog.com.br/embarcador/api/pedido/incluir` | Corpo grande (grupos `rem`, `tomador`, `exp`, `des`, `dfe[]`, `volume[]`). Retorna `codigo`, `shipmentId`, `status`; com etiqueta habilitada, `etiqueta.arquivo` (PDF) e `etiqueta.volume[]`. |
| Cancelar pedido | `POST https://www.jadlog.com.br/embarcador/api/pedido/cancelar` | `{"shipmentId":"..."}` ou `{"codigo":"..."}`. |
| Rastrear (completo) | `POST https://prd-traffic.jadlogtech.com.br/embarcador/api/tracking/consultar` | `{"consulta":[{codigo|shipmentId|cte|pedido|df{...}}]}` até 20 itens (texto fala em 100). Resposta: `consulta[].tracking{codigo,shipmentId,dacte,dtEmissao,status,valor,peso,eventos[{data,status,unidade}],volumes[]}` + `previsaoEntrega`. |
| Rastrear (último status) | `POST https://prd-traffic.jadlogtech.com.br/embarcador/api/tracking/simples/consultar` | Mesmo corpo; até 500 itens; só o último status. |
| XML do CT-e | `POST https://www.jadlog.com.br/embarcador/api/cte/xml` | `{"dacte":"..."}` → XML. Fora do MVP. |
| Pontos Pickup | `GET .../pickup/address/pudos[/location]?tipos=...&cep=` | Fora do MVP. |
| QRCode Dropoff | `GET https://www.jadlog.com.br/qrcodeservice/api/{id}` | Base64 do QRCode; só modalidade Pickup Dropoff. |
| Incluir tratativa | `POST .../tracking/incluir_tratativa` | Fora do MVP. Erros `{"descricao","id"}` (1..7). |

**Formato de erro** (inclusão/cancelamento): `{"status":"Erro ao inserir solicitação.","erro":{"id":-2,"descricao":"Destino fora da área de cobertura","detalhe":"..."}}`.
Simulador: `{"frete":[...],"error":{"id":-1,"descricao":"frete[0].contrato Numero de contrato invalido"}}` — note `error` (exemplo) vs `erro` (tabela de campos): o parser aceita os dois.
Tratativa: HTTP 401 + `{"descricao":"TOKEN INVALIDO","id":1}`.

**Rastreio por push**: **não existe webhook**. Só consulta (polling).
**Etiqueta**: não há endpoint próprio; vem no retorno de `pedido/incluir` (`etiqueta.arquivo`, PDF em `byte[]` — premissa: base64 no JSON) quando habilitada por contrato ("Smart Label").
**Devolução**: não há endpoint de logística reversa. O PDF cita serviços Dropoff/Reversa pelo campo `servico` (`0` sem PIN, `1` com PIN, `2` Dropoff, `3` Dropoff Pudos) e QRCode, só para modalidade Pickup.

## Correios (Correios API / CWS)

**Hosts**: homologação `https://apihom.correios.com.br`, produção `https://api.correios.com.br` (este último só aparece nos exemplos da API Pedido Informação).
**Autenticação (2 passos)**:
1. `POST /token/v1/autentica/cartaopostagem` com **Basic Auth** (usuário do Meu Correios + senha/código de acesso à API) e corpo `{"numero":"<cartão de postagem>"}`. Retorna **201** com `ambiente,id,perfil,cnpj,cartaoPostagem{numero,contrato,dr,api[]},api[],emissao,expiraEm,token`. Validade do exemplo: 24 h (`expiraEm = emissao + 1 dia`). Deve ser cacheado em Redis até pouco antes de `expiraEm`.
2. Demais chamadas: `Authorization: Bearer <token>`, `Accept: application/json`.
Requer contrato ativo; APIs "restritas" aparecem só após cadastrar o serviço no cartão de postagem.

| Operação | Método e URL | Observações |
|---|---|---|
| Prazo (1 serviço) | `GET /prazo/v1/nacional/{coProduto}?cepOrigem=&cepDestino=` | → `{coProduto,prazoEntrega(int, dias),dataMaxima,entregaDomiciliar(S/N),entregaSabado(S/N)}` |
| Prazo (lote) | `POST /prazo/v1/nacional` | `{"idLote","parametrosPrazo":[{cepDestino,cepOrigem,coProduto,nuRequisicao,dtEvento(dd/mm/aaaa)}]}` → array |
| Preço (1 serviço) | `GET /preco/v1/nacional/{codigoServico}?cepDestino&cepOrigem&psObjeto(g)&tpObjeto&comprimento&largura&altura&servicosAdicionais&vlDeclarado` | Valores são **strings em pt-BR** (`"19,92"`): `pcFinal` é o total. |
| Preço (lote, até 5) | `POST /preco/v1/nacional` | `{"idLote","parametrosProduto":[{coProduto,nuRequisicao,cepOrigem,cepDestino,psObjeto,tpObjeto,comprimento,largura,altura,servicosAdicionais:[{coServAdicional}],vlDeclarado,dtEvento}]}` → array |
| Pré-postagem (criar remessa) | `POST /prepostagem/v1/prepostagens` | Retorna **200** com `id` da pré-postagem (`PR...`, 24 chars) + dados de remetente/destinatário. Corpo (tela parcial): `codigoServico, codigoFormatoObjetoInformado, alturaInformada, larguraInformada, comprimentoInformado, diametroInformado, pesoCubico, pesoInformado, tipoDocumento, precoPostagem, remetente{...}`. **Schema completo só no CWS** (não está no PDF). `codigoObjeto` pode ser omitido: o sistema atribui o número de rastreio. |
| Rótulo (etiqueta) | `POST /prepostagem/v1/prepostagens/rotulo/assincrono/pdf` `{"idsPrePostagem":[...],"tipoRotulo":"P"\|"R","formatoRotulo":"ET"}`; síncrono: `POST /prepostagem/v1/prepostagens/rotulo` | Assíncrono: devolve dados para consultar e depois baixar (resposta **não** documentada no PDF). Só com as mesmas credenciais da criação. |
| Cancelar | `DELETE /prepostagem/v1/prepostagens/{id}` ou `DELETE /prepostagem/v1/prepostagens/objeto/{codigoObjeto}` | |
| Consultar | `GET /prepostagem/v2/prepostagens` (paginada, 100 dias) | |
| Declaração de conteúdo / AR | `GET /prepostagem/v1/prepostagens/declaracaoconteudo/{ids}` / `.../avisorecebimento/{ids}` | Retorno HTML. Fora do MVP. |
| Faixas de etiquetas | `POST /v1/prepostagens/rotulo/range`; `GET .../rotulo/{contrato}/solicitadas` e `/saldo` | Fora do MVP (usamos o número atribuído pelo sistema). |
| Rastro | `GET /srorastro/v1/objetos/{codigoObjeto}?resultado=T` | `objetos[].eventos[]{codigo,tipo,dtHrCriado,descricao,detalhe,unidade{tipo,endereco{uf,cidade}}}`; `dtPrevista`. Eventos do exemplo: `PO` postado, `RO` em trânsito, `OEC` saiu para entrega, `BDE` entregue. |
| CEP | `GET /cep/v1/enderecos/{cep}` | Fora do MVP. |

**Formato de erro** (só documentado na API CEP, mesmo gateway): `404 {"cep":"...","txMsg":"CEP-003: O CEP ... não foi encontrado"}`;
`409 {"msgs":["CEP-084: CEP no formato incorreto. 8 dígitos..."],"date","path","cause":"ConstraintViolationException: ..."}`.
(O texto do PDF diz "HTTP 400"; as telas mostram 404 e 409.)
**Rastreio por push**: **não existe webhook**; só consulta (Rastro).
**Devolução / logística reversa**: **não documentada**. O PDF só descreve pré-postagem.
Pré-postagem **expira em 7 dias** corridos se não for postada.

## Divergências em relação ao enunciado

1. **Não há webhook em nenhuma das duas transportadoras.** O rastreio real é por polling (`PollRastreioJob`). A rota `POST /webhooks/rastreio/{transportadora}` continua existindo (simulador, testes e futuras transportadoras), mas a assinatura HMAC é um **esquema nosso**, não da transportadora.
2. **Não há endpoint de devolução em nenhum PDF.** `createDevolucao` é modelado como nova remessa com origem/destino invertidos (ver premissas).
3. **`parseCotacao(Response)` não basta para os Correios**: preço e prazo são APIs separadas (2 respostas). O contrato passa a receber um array de respostas indexado pela chave usada em `prepareCotacao`.
4. **`getEtiqueta`**: Jadlog devolve a etiqueta dentro de `pedido/incluir`; Correios gera sob demanda (assíncrono). O contrato expõe a etiqueta opcional em `RemessaResultado` e `getEtiqueta` só é chamado se não houver PDF guardado.
5. **Rastro dos Correios**: o texto cita a base `https://apphom.correios.com.br/rastro`; o `curl` do próprio manual usa `/srorastro/v1/objetos/...`. Seguimos o `curl` (executável).
6. **Jadlog `Authorization`**: o PDF mostra `Authorization: <token>` (sem `Bearer`). Seguimos o PDF; o valor completo do header é o que o cliente cadastra.
7. A tabela de modalidades Jadlog (a "tabela 2") **não está** no PDF.

## Premissas (validar antes de produção)

- **P1** Jadlog `etiqueta.arquivo` = PDF em base64 dentro do JSON.
- **P2** Jadlog: os códigos de `modalidade` e `servico` são os que o cliente cadastra por contrato (campo livre na credencial); o PDF não traz a tabela.
- **P3** Jadlog: `vltotal`/`valor` em reais (double) → converter para centavos com arredondamento; peso em kg; dimensões em cm.
- **P4** Jadlog: timestamps de rastreio (`"2023-03-22 15:12:06"`) estão no fuso de Brasília.
- **P5** Correios: corpo completo da pré-postagem e resposta (incl. `codigoObjeto`) seguem os nomes do CWS; o mapper usa só os campos vistos no PDF + `codigoObjeto` (premissa).
- **P6** Correios: resposta do rótulo assíncrono = recibo (`idRecibo`) a consultar/baixar em `.../rotulo/download/assincrono/{idRecibo}`; **não está no PDF**.
- **P7** Correios: erros de preço/prazo/pré-postagem seguem o mesmo padrão `msgs[]`/`txMsg` da API CEP; HTTP 401 para token inválido/expirado.
- **P8** Correios: códigos de serviço contratados vêm da credencial do cliente (exemplos do PDF: `03220`, `04162` SEDEX; `04669` PAC); `tpObjeto=2` (pacote/caixa), `psObjeto` em gramas.
- **P9** Correios: mapa de status de rastreio por `codigo` do evento além dos 4 exemplos do PDF é nosso e deve ser revisado com a tabela oficial SRO.
- **P10** Devolução Jadlog = `pedido/incluir` com origem/destino invertidos e `servico=2` (Dropoff), recebendo QRCode por `qrcodeservice`; Devolução Correios = pré-postagem com remetente/destinatário invertidos.
- **P11** Webhook de rastreio: assinatura HMAC-SHA256 sobre `"{timestamp}.{corpo}"`, header `X-Frete-Assinatura`/`X-Frete-Timestamp`, segredo por transportadora/cliente. Como as transportadoras não chamam webhook, o risco é só de superfície exposta; mitigado por assinatura, janela anti-replay, rate limit por IP e limite de corpo.
