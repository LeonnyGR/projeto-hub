<?php

declare(strict_types=1);

namespace App\Transportadoras;

/**
 * O que toda transportadora precisa saber fazer. Para adicionar uma nova:
 *  1. copie Jadlog.php ou Correios.php e ajuste URLs e campos;
 *  2. registre o nome em Transportadoras::LISTA.
 *
 * A interface cresce junto com o projeto (o rastreio entra na proxima fase).
 */
interface Transportadora
{
    /**
     * Regras de validacao das credenciais e da configuracao desta transportadora (usadas pelo comando
     * `transportadora:configurar`). Os campos de "credenciais" sao secretos (guardados criptografados);
     * os de "configuracao" nao.
     */
    public static function regras(): array;

    /**
     * Perguntas que o comando `transportadora:configurar` faz, na ordem. Cada item:
     *  - campo:   onde o valor entra ("credenciais.token", "configuracao.tomador.nome"...);
     *  - rotulo:  o texto da pergunta;
     *  - secreto: true = o que for digitado nao aparece na tela;
     *  - padrao:  resposta usada quando se aperta Enter (opcional);
     *  - tipo:    'servicos' = lista "codigo:nome,codigo:nome" (opcional).
     */
    public static function perguntas(): array;

    /** Codigo usado na API e no banco: 'jadlog', 'correios'. */
    public function codigo(): string;

    /**
     * Descreve as chamadas HTTP necessarias para cotar (nao envia nada).
     * O CotacaoService envia todas as transportadoras ao mesmo tempo.
     *
     * Cada item: ['chave' => 'nome-unico', 'metodo' => 'POST', 'url' => '...', 'headers' => [], 'query' => [], 'corpo' => []|null]
     *
     * @param  array  $dados  cotacao ja validada: cep_origem, cep_destino, valor_declarado_centavos, volumes[]
     */
    public function pedidosDeCotacao(array $dados): array;

    /**
     * Le as respostas e devolve as opcoes de frete:
     * [['servico_codigo' => '3', 'servico_nome' => '...', 'valor_centavos' => 750, 'prazo_dias' => 5], ...]
     *
     * @param  array  $respostas  [chave => Illuminate\Http\Client\Response|null] (null = sem resposta)
     * @throws TransportadoraIndisponivel quando nenhuma resposta serviu
     */
    public function lerCotacao(array $dados, array $respostas): array;

    /**
     * Cria a remessa na transportadora.
     *
     * Entrada ($remessa):
     *   id, tipo ('envio'|'devolucao'), servico_codigo, valor_declarado_centavos, numero_pedido (ou null),
     *   conteudo, ponto_retirada_id (ou null), documento_fiscal (ou null),
     *   remetente e destinatario (quem despacha e quem recebe NESTA remessa),
     *   volumes[] (peso_gramas, altura_cm, largura_cm, comprimento_cm)
     *   Cada pessoa: nome, documento, telefone, email, endereco[cep, logradouro, numero, complemento, bairro, cidade, uf]
     *
     * Saida:
     *   id_externo, codigo_rastreio,
     *   volumes[] (na mesma ordem da entrada, cada um com id_externo e codigo_rastreio),
     *   etiqueta_pdf (conteudo do PDF, ou null se a transportadora nao devolveu agora)
     *
     * @throws \App\Exceptions\ErroDeNegocio('remessa_recusada') se a transportadora recusou os dados
     * @throws TransportadoraIndisponivel se nao respondeu direito
     */
    public function criarRemessa(array $remessa): array;

    /**
     * Cancela na transportadora. Cancelar algo que ja esta cancelado nao e erro.
     *
     * @param  array  $remessa  id_externo e volumes[] (cada um com id_externo), como guardados em nossa Remessa
     * @throws \App\Exceptions\ErroDeNegocio('cancelamento_recusado') se a transportadora recusou
     * @throws TransportadoraIndisponivel se nao respondeu direito
     */
    public function cancelarRemessa(array $remessa): void;

    /**
     * Busca o PDF da etiqueta na transportadora. Devolve null se ela so entrega a etiqueta na criacao
     * (Jadlog) ou se ainda nao esta disponivel.
     *
     * @param  array  $remessa  id_externo e volumes[], como guardados em nossa Remessa
     */
    public function etiqueta(array $remessa): ?string;
}
