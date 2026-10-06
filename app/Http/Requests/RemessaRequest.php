<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Pessoa;
use App\Transportadoras\Transportadoras;
use Illuminate\Validation\Rule;

/** Criar uma remessa (envio). O header Idempotency-Key e obrigatorio. */
class RemessaRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        // A chave vem no header; entra na validacao como se fosse um campo.
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);

        foreach (['remetente', 'destinatario'] as $quem) {
            $this->merge([$quem => Pessoa::normalizar($this->input($quem))]);
        }
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'cotacao_id' => ['required', 'uuid'],
            // Opcao escolhida na cotacao
            'transportadora' => ['required', 'string', Rule::in(Transportadoras::codigos())],
            'servico_codigo' => ['required', 'string', 'max:40'],

            'numero_pedido' => ['nullable', 'string', 'max:20'],
            'conteudo' => ['nullable', 'string', 'max:80'],
            // Ponto de retirada escolhido pelo cliente (id vindo de GET /pontos-retirada)
            'ponto_retirada_id' => ['nullable', 'string', 'max:7'],

            // Nota fiscal. Sem ela, enviamos uma declaracao de conteudo.
            'documento_fiscal' => ['nullable', 'array'],
            'documento_fiscal.tipo' => ['required_with:documento_fiscal', Rule::in(['declaracao', 'nf', 'nfe', 'cte', 'dce'])],
            'documento_fiscal.chave' => ['nullable', 'required_if:documento_fiscal.tipo,nfe,cte,dce', 'digits:44'],
            'documento_fiscal.numero' => ['nullable', 'required_if:documento_fiscal.tipo,nf,nfe,cte,dce', 'string', 'max:20'],
            'documento_fiscal.serie' => ['nullable', 'required_if:documento_fiscal.tipo,nfe,cte,dce', 'string', 'max:3'],
            'documento_fiscal.cfop' => ['nullable', 'required_if:documento_fiscal.tipo,nf,nfe', 'digits:4'],

            ...Pessoa::regras('remetente'),
            ...Pessoa::regras('destinatario'),
        ];
    }
}
