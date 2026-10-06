# Premissas sobre a Jadlog e os Correios

Os PDFs não explicam tudo. Onde faltou informação, o código segue uma **premissa**. Esta página lista cada uma,
com um exemplo, o risco e quem pode confirmar. Quando uma premissa for confirmada (ou corrigida), atualize aqui.

Legenda: **Confirmar com** diz a quem perguntar. Quase tudo se resolve com o contato comercial da transportadora
ou olhando o catálogo dos Correios (CWS) com uma conta de homologação.

## Cotação

### 1. Divisor da cubagem da Jadlog (6000)
- **O que supomos:** o peso cubado é `comprimento × largura × altura (cm) ÷ 6000`.
- **Exemplo:** caixa de 60 × 50 × 40 cm, pesando 0,5 kg. O volume é 120.000 cm³, e 120.000 ÷ 6000 = 20 kg. Enviamos 20 kg à Jadlog. Se o divisor real for 5000, seriam 24 kg.
- **Risco:** o preço da cotação sai diferente do cobrado.
- **Onde mudar:** `config/transportadoras.php` (`divisor_cubagem`).
- **Confirmar com:** Jadlog (contrato).

### 2. Modalidades da Jadlog
- **O que supomos:** o PDF cita "tabela 2" de modalidades, mas ela não está no arquivo. Quem cadastra o seller informa os códigos contratados (`3:Package,0:Expresso`).
- **Exemplo:** se o contrato tem a modalidade `5` e o cadastro diz `3`, a Jadlog recusa o item e a cotação sai sem essa opção.
- **Confirmar com:** Jadlog.

### 3. Correios: preço por volume
- **O que supomos:** a API de preço calcula um objeto por vez. Somamos o preço de cada volume. O serviço só aparece se tivermos prazo e preço de todos os volumes.
- **Exemplo:** SEDEX, caixa 1 = R$ 19,92 e caixa 2 = R$ 15,00, então a cotação mostra R$ 34,92.

### 4. Correios: tipo e formato do objeto
- **O que supomos:** `tpObjeto = 2` (pacote/caixa) e `psObjeto` em gramas, como nos exemplos do PDF. Valor declarado enviado como texto com ponto (`"100.50"`).
- **Confirmar com:** catálogo CWS dos Correios.

### 5. Fuso do token dos Correios
- **O que supomos:** `expiraEm` (ex.: `2022-03-19T10:26:51`) vem sem fuso; assumimos horário de Brasília para saber até quando guardar o token.
- **Risco:** se estiver em UTC, o token seria guardado por menos tempo que o real. Sem prejuízo: só pediríamos um token novo mais cedo.

## Remessa

### 6. Jadlog: código de rastreio = `shipmentId`
- **O que supomos:** ao criar o pedido, a Jadlog devolve `codigo` e `shipmentId`. Usamos o `shipmentId` como `id_externo` e `codigo_rastreio` (serve para cancelar e rastrear, segundo o PDF).
- **Observação:** o número do CT-e (que o destinatário costuma usar) só existe depois da emissão. Na fase de rastreio podemos trocá-lo.
- **Todos os volumes de uma remessa Jadlog têm o mesmo código.**

### 7. Jadlog: quem é o tomador
- **O que fazemos:** o tomador (quem paga o frete) é sempre o **titular do contrato**, ou seja, o B2C. Os dados dele (nome, documento, endereço, telefone) ficam no cadastro do contrato (`configuracao.tomador`, cadastrado com `transportadora:configurar jadlog`). O remetente e o expedidor são o seller.
- **Exemplo:** devolução de uma compra: quem despacha é a cliente (Maria), o destino é a loja do seller, mas quem paga é a Bartofil. Por isso `tomador` = Bartofil, `exp` = Maria.
- **Confirmar com:** Jadlog. Um mesmo contrato pode ter remetentes (CNPJ/CPF) diferentes do tomador? Em marketplaces costuma poder, mas não está no PDF.

### 8. Jadlog: nota fiscal
- **O que o PDF diz:** o grupo `dfe` (documento fiscal) é obrigatório.
- **O que fazemos:** se o seller não informa nota, enviamos uma declaração de conteúdo manual (`tpDocumento = 0`, `nrDoc = DEC` + 8 caracteres do id), como o PDF orienta.

### 9. Jadlog: coleta (`tpColeta`)
- **O que supomos:** `K` (a Jadlog coleta no remetente) por padrão. O seller pode configurar `S` (ele leva o pacote até a Jadlog). Devolução sempre usa `S`.
- **Confirmar com:** Jadlog (qual é o combinado de vocês).

### 10. Correios: corpo da pré-postagem
- **O que o PDF mostra:** só uma tela parcial do JSON (`codigoServico`, `codigoFormatoObjetoInformado`, `alturaInformada`, `larguraInformada`, `comprimentoInformado`, `diametroInformado`, `pesoCubico`, `pesoInformado`...) e a estrutura de `remetente`.
- **O que enviamos:** `remetente`, `destinatario`, `codigoServico`, `codigoFormatoObjetoInformado`, peso e medidas. Para nota fiscal: `numeroNotaFiscal` e `chaveNFe` (nomes não confirmados).
- **Risco:** o CWS pode exigir mais campos (por exemplo, declaração de conteúdo ou ciência de objeto proibido). Se a API recusar, o seller recebe `remessa_recusada` e o detalhe fica em `chamadas_transportadoras`.
- **Confirmar com:** CWS dos Correios, em homologação. É o item mais importante antes de usar em produção.
- **Também confirmar:** o remetente de cada pré-postagem é o seller, mas o cartão de postagem é do B2C. Os Correios aceitam remetente diferente do titular do cartão?

### 11. Correios: número de rastreio da pré-postagem
- **O que supomos:** a resposta traz `id` (`PR...`, que aparece no PDF) e `codigoObjeto` (o código de rastreio; não aparece no PDF).
- **Risco:** se o campo tiver outro nome, a criação falha com `resposta_invalida`.

### 12. Correios: um código por volume
- **O que fazemos:** cada volume vira uma pré-postagem, com seu próprio `id` e código de rastreio. A remessa mostra todos em `volumes[]`.
- **Se um dos volumes falhar:** os que já foram criados são cancelados.

### 13. Correios: etiqueta
- **O que o PDF mostra:** só o pedido (`POST .../rotulo/assincrono/pdf` com `idsPrePostagem`, `tipoRotulo`, `formatoRotulo`).
- **O que supomos:** a resposta traz um `idRecibo`, e o PDF (em base64, campo `dados`) é baixado em `GET .../rotulo/download/assincrono/{idRecibo}`. Tentamos baixar até 3 vezes.
- **Confirmar com:** CWS dos Correios.

### 14. Correios: cancelar
- **O que supomos:** `DELETE /prepostagem/v1/prepostagens/{id}`, resposta 2xx = cancelado, 404 = já não existe (tratamos como já cancelado).

## Devolução

### 15. Não existe endpoint de devolução nos PDFs
- **O que fazemos:** a devolução é uma nova remessa com origem e destino trocados, na mesma transportadora e serviço da remessa original.
  - Jadlog: `pedido/incluir` com `servico = 2` (Dropoff) e `tpColeta = S`.
  - Correios: pré-postagem com remetente e destinatário trocados.
- **Regra:** só vale para remessa **entregue**. Para remessa ainda em transporte, o fluxograma pede "bloqueio de entrega", que ainda não existe.
- **Valor do frete:** não calculamos; fica `null` (cobrado conforme o contrato).
- **Confirmar com:** as duas transportadoras (existe um serviço de logística reversa próprio?).

## Riscos que continuam abertos

- **Timeout na criação.** Se a transportadora criar o pedido mas não responder a tempo, nós apagamos nossa reserva e o seller pode tentar de novo, o que **pode duplicar** o pedido na transportadora. A Jadlog permite enviarmos nosso próprio `shipmentId`, o que resolveria. Precisa de confirmação com a Jadlog.
- **Jadlog sem homologação.** Em modo `real`, criar remessa cria um pedido de verdade.
