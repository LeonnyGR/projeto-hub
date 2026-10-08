# Glossário

Nomes do domínio em português, sem acento, no código, nas tabelas e na API. Termos técnicos em inglês
(`Service`, `Controller`, `Request`, `Resource`, `get`, `create`...).

A coluna "Não usar" mostra os nomes que as transportadoras usam. Eles só podem aparecer dentro de
`app/Transportadoras/Jadlog.php` e `app/Transportadoras/Correios.php`.

| Termo | O que é | Não usar |
|---|---|---|
| `Cliente` | Quem usa o hub: um lojista, o B2C... É o **tenant**: tem a própria conta, o próprio contrato com as transportadoras, as próprias cotações e remessas, e nunca vê dados de outro cliente (404). Tem `id`, `nome`, `cnpj` (único) e `ativo`. Cadastrado por nós pela API de administração (`POST /admin/clientes`), junto com o dono. Tabela `clientes`. | usuario, consumidor |
| Usuário | **Pessoa** que entra no portal com e-mail e senha. Pertence a um cliente e tem um papel. Tabela `usuarios`. Não confundir com o cliente (a empresa). | cliente (para pessoa) |
| `adm_tenant` | Papel do **dono** do cliente. Faz tudo no portal: transportadoras, chaves de API, operadores e o dia a dia. É criado junto com o cliente. | admin (admin é o token da administração) |
| `operador_tenant` | Papel do **funcionário** do cliente, cadastrado pelo dono. Só faz o dia a dia (cotar, remessas, devoluções, etiquetas, consultas). Nas rotas de dono recebe **403**. | |
| Portal | A porta de entrada das **pessoas** do cliente: `/api/v1/portal/...`, com login por e-mail e senha. Usa as mesmas rotas de negócio da chave de API, mais as de dono (chaves, operadores). | painel |
| Chave de API | Credencial do **backend** do cliente (máquina): `fs_<prefixo>.<segredo>`, no header `Authorization: Bearer`. Um cliente tem várias (`producao`, `homologacao`), cada uma revogável. O texto completo aparece **uma única vez**, ao criar (no banco só o hash sha256 do segredo). Criada pelo dono no portal (`POST /portal/chaves`). Nunca vai para o navegador ou o app. | token (reservado para o token das transportadoras), senha |
| Prefixo (da chave) | Início **público** da chave de API (`fs_3fa91c0b2d4e`). Serve para achar a chave no banco e reconhecê-la numa lista. Não é segredo. | |
| Segredo (da chave) | A parte secreta da chave de API, depois do ponto. Aleatório, 32 bytes; no banco só o hash. | |
| Revogar | Invalidar uma chave de API (`DELETE /portal/chaves/{id}`). Quem a usa passa a receber 401. As outras chaves continuam. | apagar |
| Token de admin (`ADMIN_API_TOKEN`) | Senha única das rotas `/api/v1/admin` (cadastro de clientes), usada só por nós. Vem do `.env` do servidor (`openssl rand -hex 32`) e vai no header `Authorization: Bearer`. Vazio ou com menos de 32 caracteres = administração desligada (tudo 401). É **mais poderoso que uma chave de API**: nunca vai para o navegador. | token (sozinho), chave de API |
| Sessão | O "estar logado" do portal: o servidor guarda a sessão no **Redis** e o navegador guarda só um cookie com o número dela. Expira após `SESSION_LIFETIME` minutos sem uso. Em `.env` antigo, `SESSION_DRIVER=array` faz o login não persistir. | |
| Cookie `HttpOnly` | Cookie que o **JavaScript da página não consegue ler**, só o navegador o envia. Protege a sessão contra roubo por script injetado. | |
| `SameSite` | Regra do cookie sobre **de onde** a chamada pode vir. `Strict` (o nosso): o navegador só envia o cookie se a chamada partir do **mesmo site** (mesmo domínio registrável, ex.: `portal.bartofil.com.br` e `hub.bartofil.com.br`). Também barra ataques CSRF. | |
| `Secure` | Cookie só trafega por HTTPS. Liga sozinho com `APP_ENV=production`. | |
| Lojista | Quem vende e envia o pacote. Aparece na remessa como **remetente**. Pode ser o próprio cliente. | |
| `cep_origem` | CEP de onde o pacote sai. Quem chama (o B2C) informa em cada cotação; não fica no cadastro do cliente. | |
| `Transportadora` | Jadlog ou Correios (`jadlog`, `correios`). | carrier, operador |
| Catálogo (de campos) | A lista das transportadoras que o hub atende e dos campos de cadastro de cada uma (`GET /transportadoras/catalogo`). O portal monta o formulário a partir dela. Vem do código das classes de transportadora, não do banco. | formulário dinâmico |
| Máscara (`credenciais_mascaradas`) | A credencial mostrada só em parte: `••••••1234` (6 bolinhas + 4 últimos caracteres, para valores de 12+ caracteres) ou `••••••••` (valores curtos). Nunca o valor inteiro. | |
| `CredencialTransportadora` | Contrato de um cliente com uma transportadora (token, cartão de postagem). | conta, login |
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
| `Idempotency-Key` | Header que impede criar a mesma remessa duas vezes se o cliente repetir a chamada. | |
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
| `despachada` | O cliente avisou que entregou o pacote à transportadora |
| `em_transito` | Pacote a caminho |
| `saiu_para_entrega` | Em rota de entrega |
| `entregue` | Entregue ao destinatário |
| `cancelada` | Cancelada antes da postagem |
