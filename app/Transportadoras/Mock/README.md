# Simulador das transportadoras (modo mock)

Serve para desenvolver e testar sem conta real na Jadlog ou nos Correios.

Com `TRANSPORTADORAS_MODO=mock`, as classes `Jadlog` e `Correios` rodam normalmente (montam o pedido,
fazem a chamada HTTP e leem a resposta). A única coisa falsa é a resposta da internet: ela vem de
`SimuladorHttp.php`, que lê os arquivos da pasta `fixtures/`.

- As fixtures das transportadoras são cópias dos exemplos dos PDFs (mesmos campos, tipos e nomes). A do ViaCEP segue a documentação pública do serviço.
- O ViaCEP **não é simulado** por padrão: a consulta de CEP vai para o ViaCEP de verdade (`VIACEP_MODO=real`). Só os testes automáticos usam o simulado.
- O modo mock **não funciona com `APP_ENV=production`**: o sistema não sobe.
- Para falar com as transportadoras de verdade, use `TRANSPORTADORAS_MODO=real`.

## Como escolher o que o simulador responde

O cenário vem do **CEP de destino** (da cotação, do destinatário da remessa ou do CEP consultado).

| CEP de destino | O que acontece | O que o seller vê |
|---|---|---|
| qualquer outro CEP | Sucesso. Uma opção por serviço configurado | Opções com valor e prazo |
| `22222222` | Sem cobertura. Jadlog devolve o erro do PDF (`error.id` -1 na cotação, `erro.id` -2 ao criar remessa). Correios devolve 409 (cotação e criação). Consulta de CEP (ViaCEP): `{"erro": true}`. Pontos de retirada: lista vazia | Cotação: falha `sem_cobertura`. Remessa: erro 422 `remessa_recusada`. CEP: 404 |
| `33333333` | Timeout (sem resposta) | Falha `sem_resposta` |
| `44444444` | HTTP 500 com página HTML | Falha `erro_na_transportadora` |
| `55555555` | Resposta quebrada (JSON incompleto) | Falha `resposta_invalida` |
| `66666666` | HTTP 401 (credencial recusada) | Falha `credencial_invalida` |

Credencial recusada também pode ser simulada pela própria credencial: se o token da Jadlog for
`invalido`, ou o usuário dos Correios for `invalido`, a transportadora responde 401.

## Cancelamento e etiqueta

| Situação | Como pedir ao simulador |
|---|---|
| Jadlog: pedido "já está cancelado" | `id_externo` (shipmentId) terminando em `99` |
| Jadlog: transportadora recusa cancelar | `id_externo` terminando em `77` |
| Correios: pré-postagem não existe mais (404, tratado como já cancelada) | id terminando em `NAOEXISTE` |
| Correios: transportadora recusa cancelar (409) | id terminando em `RECUSA` |

- **Criar remessa na Jadlog** devolve `shipmentId` de 14 dígitos e a etiqueta (PDF em base64), como o PDF descreve.
- **Criar remessa nos Correios** devolve o `id` (`PR...`) e o `codigoObjeto` (`AA123456789BR`).
- **Etiqueta dos Correios** é pedida em duas etapas (pedir e baixar). O formato dessa resposta **não está no PDF**: é premissa (ver `docs/premissas-das-transportadoras.md`).
- O PDF das etiquetas do simulador é `fixtures/etiqueta_exemplo.pdf`.

## Valores devolvidos (previsíveis)

- **Jadlog**: valor = 5 + 2 × modalidade + 3 × peso(kg), prazo = 3 + (modalidade mod 5) dias.
- **Correios**: valor = 10 + (final do código do serviço mod 10) + 2 × peso(kg), em texto brasileiro (`"19,92"`);
  prazo = 1 + (final do código mod 6) dias.

## Observação sobre o timeout

O simulador não espera o tempo passar: no cenário `33333333` ele responde direto com "falha de conexão
por timeout" (é o mesmo erro que a biblioteca de HTTP produz quando o tempo de 3 segundos estoura).
