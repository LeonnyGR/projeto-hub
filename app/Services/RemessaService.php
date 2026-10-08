<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatusRemessa;
use App\Exceptions\ErroDeNegocio;
use App\Models\CredencialTransportadora;
use App\Models\Remessa;
use App\Models\Cliente;
use App\Transportadoras\TransportadoraIndisponivel;
use App\Transportadoras\Transportadoras;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Remessas (envios e devolucoes): criar, despachar, cancelar e etiqueta.
 *
 * Idempotencia: o cliente manda o header Idempotency-Key. Repetir a chamada com a mesma chave e o mesmo
 * pedido devolve a remessa que ja existe (sem criar outra na transportadora). Mesma chave com pedido
 * diferente da erro. Quem garante isso e a constraint unica (cliente_id, idempotency_key) no banco.
 */
class RemessaService
{
    private const CAMPOS_VOLUME = ['peso_gramas', 'altura_cm', 'largura_cm', 'comprimento_cm'];

    // ------------------------------------------------------------------ criar envio

    /**
     * @param  array  $dados  campos validados de RemessaRequest
     * @return array  [Remessa, bool $jaExistia]
     */
    public function criarEnvio(Cliente $cliente, array $dados): array
    {
        $hash = $this->hashDoPedido('envio', $dados);

        // 1. Repeticao? (vem antes de tudo: a mesma chamada precisa dar a mesma resposta mesmo se a cotacao ja expirou)
        if ($existente = $cliente->remessas()->where('idempotency_key', $dados['idempotency_key'])->first()) {
            return [$this->reaproveitar($existente, $hash), true];
        }

        // 2. A cotacao e a opcao escolhida precisam ser validas.
        $cotacao = $cliente->cotacoes()->find($dados['cotacao_id'])
            ?? throw new ErroDeNegocio('cotacao_nao_encontrada', 'Cotacao nao encontrada.', 404);

        if ($cotacao->expirada()) {
            throw new ErroDeNegocio('cotacao_expirada', 'A cotacao expirou. Faca uma nova cotacao.', 422);
        }

        $opcao = collect($cotacao->resultados['opcoes'])->first(
            fn (array $o) => $o['transportadora'] === $dados['transportadora'] && (string) $o['servico_codigo'] === $dados['servico_codigo']
        ) ?? throw new ErroDeNegocio('opcao_invalida', 'Esta transportadora/servico nao esta na cotacao.', 422);

        // O preco foi calculado para estes CEPs; se forem outros, a cotacao nao vale para esta remessa.
        if ($dados['remetente']['endereco']['cep'] !== $cotacao->cep_origem || $dados['destinatario']['endereco']['cep'] !== $cotacao->cep_destino) {
            throw new ErroDeNegocio('cep_diferente_da_cotacao', 'Os CEPs de remetente e destinatario precisam ser os mesmos da cotacao.', 422);
        }

        $credencial = $this->credencial($cliente, $dados['transportadora']);

        // 3. Reserva a chave no banco (status "criando") antes de falar com a transportadora.
        $remessa = $this->reservar($cliente, $hash, [
            'tipo' => 'envio',
            'cotacao_id' => $cotacao->id,
            'idempotency_key' => $dados['idempotency_key'],
            'transportadora' => $dados['transportadora'],
            'servico_codigo' => $dados['servico_codigo'],
            'valor_frete_centavos' => $opcao['valor_centavos'],
            'prazo_dias' => $opcao['prazo_dias'],
            'remetente' => $dados['remetente'],
            'destinatario' => $dados['destinatario'],
            'volumes' => $cotacao->requisicao['volumes'],
        ]);

        if ($remessa === null) { // outra requisicao com a mesma chave chegou primeiro
            return [$this->reaproveitar($cliente->remessas()->where('idempotency_key', $dados['idempotency_key'])->firstOrFail(), $hash), true];
        }

        // 4. Cria na transportadora.
        $this->criarNaTransportadora($remessa, $credencial, [
            'id' => $remessa->id,
            'tipo' => 'envio',
            'servico_codigo' => $dados['servico_codigo'],
            'valor_declarado_centavos' => $cotacao->requisicao['valor_declarado_centavos'],
            'numero_pedido' => $dados['numero_pedido'] ?? null,
            'conteudo' => $dados['conteudo'] ?? 'MERCADORIA',
            'ponto_retirada_id' => $dados['ponto_retirada_id'] ?? null,
            'documento_fiscal' => $dados['documento_fiscal'] ?? null,
            'remetente' => $dados['remetente'],
            'destinatario' => $dados['destinatario'],
            'volumes' => $remessa->volumes,
        ]);

        return [$remessa->refresh(), false];
    }

    // ------------------------------------------------------------------ criar devolucao

    /**
     * Devolucao = nova remessa no sentido contrario (de quem recebeu de volta para o cliente), na mesma
     * transportadora e servico da remessa original. So vale para remessa ja entregue.
     *
     * @param  array  $dados  campos validados de DevolucaoRequest
     * @return array  [Remessa, bool $jaExistia]
     */
    public function criarDevolucao(Cliente $cliente, array $dados): array
    {
        $hash = $this->hashDoPedido('devolucao', $dados);

        if ($existente = $cliente->remessas()->where('idempotency_key', $dados['idempotency_key'])->first()) {
            return [$this->reaproveitar($existente, $hash), true];
        }

        $origem = $cliente->remessas()->where('tipo', 'envio')->find($dados['remessa_id'])
            ?? throw new ErroDeNegocio('remessa_nao_encontrada', 'Remessa nao encontrada.', 404);

        if ($origem->status !== StatusRemessa::Entregue) {
            // Se ainda nao foi entregue, o caminho e bloquear a entrega na transportadora (ainda nao suportado).
            throw new ErroDeNegocio('remessa_nao_entregue', 'So e possivel criar devolucao de uma remessa ja entregue.', 422);
        }

        $jaTemDevolucao = $cliente->remessas()->where('tipo', 'devolucao')->where('remessa_origem_id', $origem->id)
            ->where('status', '!=', StatusRemessa::Cancelada->value)->exists();
        if ($jaTemDevolucao) {
            throw new ErroDeNegocio('devolucao_ja_existe', 'Esta remessa ja tem uma devolucao em andamento.', 409);
        }

        $credencial = $this->credencial($cliente, $origem->transportadora);
        $volumes = array_map(fn (array $v) => Arr::only($v, self::CAMPOS_VOLUME), $origem->volumes);

        $remessa = $this->reservar($cliente, $hash, [
            'tipo' => 'devolucao',
            'remessa_origem_id' => $origem->id,
            'motivo' => $dados['motivo'],
            'idempotency_key' => $dados['idempotency_key'],
            'transportadora' => $origem->transportadora,
            'servico_codigo' => $origem->servico_codigo,
            'valor_frete_centavos' => null,
            // Quem recebeu o pedido agora despacha, e o cliente recebe.
            'remetente' => $origem->destinatario,
            'destinatario' => $origem->remetente,
            'volumes' => $volumes,
        ]);

        if ($remessa === null) {
            return [$this->reaproveitar($cliente->remessas()->where('idempotency_key', $dados['idempotency_key'])->firstOrFail(), $hash), true];
        }

        $this->criarNaTransportadora($remessa, $credencial, [
            'id' => $remessa->id,
            'tipo' => 'devolucao',
            'servico_codigo' => $origem->servico_codigo,
            'valor_declarado_centavos' => $cliente->cotacoes()->find($origem->cotacao_id)?->requisicao['valor_declarado_centavos'] ?? 0,
            'numero_pedido' => null,
            'conteudo' => 'DEVOLUCAO',
            'ponto_retirada_id' => null,
            'documento_fiscal' => null,
            'remetente' => $origem->destinatario,
            'destinatario' => $origem->remetente,
            'volumes' => $volumes,
        ]);

        return [$remessa->refresh(), false];
    }

    // ------------------------------------------------------------------ despachar / cancelar / etiqueta

    /** O cliente avisa que entregou o pacote a transportadora. (So muda o status; nao ha chamada a transportadora.) */
    public function despachar(Remessa $remessa): Remessa
    {
        if (! $remessa->status->podeDespachar()) {
            throw new ErroDeNegocio('status_invalido', "Uma remessa {$remessa->status->value} nao pode ser despachada.", 409);
        }

        $remessa->update(['status' => StatusRemessa::Despachada, 'despachada_em' => now()]);

        Log::info('Remessa despachada', ['cliente_id' => $remessa->cliente_id, 'remessa_id' => $remessa->id]);

        return $remessa;
    }

    public function cancelar(Cliente $cliente, Remessa $remessa): Remessa
    {
        if (! $remessa->status->podeCancelar()) {
            throw new ErroDeNegocio('status_invalido', "Uma remessa {$remessa->status->value} nao pode ser cancelada.", 409);
        }

        $transportadora = Transportadoras::criar($this->credencial($cliente, $remessa->transportadora), $cliente->id);

        try {
            $transportadora->cancelarRemessa(['id_externo' => $remessa->id_externo, 'volumes' => $remessa->volumes]);
        } catch (TransportadoraIndisponivel $e) {
            throw $e->paraErroDeNegocio();
        }

        $remessa->update(['status' => StatusRemessa::Cancelada, 'cancelada_em' => now()]);

        Log::info('Remessa cancelada', ['cliente_id' => $cliente->id, 'remessa_id' => $remessa->id]);

        return $remessa;
    }

    /** Conteudo do PDF da etiqueta. Se ainda nao temos, pede a transportadora (Correios). */
    public function etiqueta(Cliente $cliente, Remessa $remessa): string
    {
        if ($remessa->status === StatusRemessa::Cancelada || $remessa->status === StatusRemessa::Criando) {
            throw new ErroDeNegocio('status_invalido', "Uma remessa {$remessa->status->value} nao tem etiqueta.", 409);
        }

        $disco = Storage::disk(config('frete.etiquetas_disco'));

        if ($remessa->etiqueta_caminho !== null && $disco->exists($remessa->etiqueta_caminho)) {
            return $disco->get($remessa->etiqueta_caminho);
        }

        $transportadora = Transportadoras::criar($this->credencial($cliente, $remessa->transportadora), $cliente->id);

        try {
            $pdf = $transportadora->etiqueta(['id_externo' => $remessa->id_externo, 'volumes' => $remessa->volumes]);
        } catch (TransportadoraIndisponivel $e) {
            throw $e->paraErroDeNegocio();
        }

        if ($pdf === null) {
            throw new ErroDeNegocio('etiqueta_indisponivel', 'A etiqueta desta remessa nao esta disponivel.', 404);
        }

        $this->guardarEtiqueta($remessa, $pdf);

        return $pdf;
    }

    // ------------------------------------------------------------------ internos

    /** Mesma chave: devolve a remessa (se o pedido for igual) ou recusa. */
    private function reaproveitar(Remessa $existente, string $hash): Remessa
    {
        if (! hash_equals($existente->hash_requisicao, $hash)) {
            throw new ErroDeNegocio('idempotency_key_reutilizada', 'Esta Idempotency-Key ja foi usada com um pedido diferente.', 422);
        }

        if ($existente->status === StatusRemessa::Criando) {
            throw new ErroDeNegocio('em_processamento', 'Esta remessa ainda esta sendo criada. Tente novamente em instantes.', 409);
        }

        return $existente;
    }

    /**
     * Grava a remessa com status "criando". Devolve null se a chave ja existe (outra requisicao chegou antes).
     */
    private function reservar(Cliente $cliente, string $hash, array $campos): ?Remessa
    {
        try {
            // A transacao serve para o erro de chave duplicada nao estragar uma transacao externa (testes).
            return DB::transaction(fn () => $cliente->remessas()->create($campos + [
                'hash_requisicao' => $hash,
                'status' => StatusRemessa::Criando,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** Chama a transportadora e completa a remessa. Se der erro, apaga a reserva para o cliente poder tentar de novo. */
    private function criarNaTransportadora(Remessa $remessa, CredencialTransportadora $credencial, array $pedido): void
    {
        try {
            $resultado = Transportadoras::criar($credencial, $remessa->cliente_id)->criarRemessa($pedido);
        } catch (Throwable $e) {
            $remessa->delete();

            Log::warning('Remessa nao criada na transportadora', ['cliente_id' => $remessa->cliente_id, 'transportadora' => $remessa->transportadora, 'erro' => $e::class]);

            throw $e instanceof TransportadoraIndisponivel ? $e->paraErroDeNegocio() : $e;
        }

        // Cada volume guarda as medidas e os codigos da transportadora.
        $volumes = [];
        foreach ($remessa->volumes as $i => $volume) {
            $volumes[] = Arr::only($volume, self::CAMPOS_VOLUME) + $resultado['volumes'][$i];
        }

        $remessa->update([
            'status' => StatusRemessa::Criada,
            'id_externo' => $resultado['id_externo'],
            'codigo_rastreio' => $resultado['codigo_rastreio'],
            'volumes' => $volumes,
        ]);

        if ($resultado['etiqueta_pdf'] !== null) {
            $this->guardarEtiqueta($remessa, $resultado['etiqueta_pdf']);
        }

        Log::info('Remessa criada', [
            'cliente_id' => $remessa->cliente_id, 'remessa_id' => $remessa->id,
            'tipo' => $remessa->tipo, 'transportadora' => $remessa->transportadora,
        ]);
    }

    /** Guarda o PDF no disco de etiquetas. Falha ao guardar nao pode desfazer uma remessa ja criada. */
    private function guardarEtiqueta(Remessa $remessa, string $pdf): void
    {
        $caminho = "{$remessa->cliente_id}/{$remessa->id}.pdf";

        try {
            Storage::disk(config('frete.etiquetas_disco'))->put($caminho, $pdf);
            $remessa->update(['etiqueta_caminho' => $caminho]);
        } catch (Throwable $e) {
            Log::error('Nao foi possivel guardar a etiqueta', ['remessa_id' => $remessa->id, 'erro' => $e::class]);
        }
    }

    private function credencial(Cliente $cliente, string $transportadora): CredencialTransportadora
    {
        return $cliente->credenciais()->where('transportadora', $transportadora)->where('ativa', true)->first()
            ?? throw new ErroDeNegocio('transportadora_nao_configurada', "Voce nao tem a transportadora '{$transportadora}' configurada.", 422);
    }

    /** Resumo do pedido, igual para pedidos iguais (a ordem dos campos nao importa). */
    private function hashDoPedido(string $tipo, array $dados): string
    {
        unset($dados['idempotency_key']);

        $ordenar = function (array $valor) use (&$ordenar): array {
            ksort($valor);

            return array_map(fn ($v) => is_array($v) && ! array_is_list($v) ? $ordenar($v) : $v, $valor);
        };

        return hash('sha256', $tipo.json_encode($ordenar($dados)));
    }
}
