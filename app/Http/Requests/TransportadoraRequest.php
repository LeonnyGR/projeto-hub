<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Pessoa;
use App\Transportadoras\Transportadoras;

/**
 * Cadastrar/substituir (PUT) ou ligar/desligar (PATCH) uma transportadora do B2C.
 * As regras de PUT vem da propria transportadora (Jadlog::regras(), Correios::regras()).
 */
class TransportadoraRequest extends ApiFormRequest
{
    /** Documento, telefone e CEP do tomador podem vir com mascara: guardamos so os numeros. */
    protected function prepareForValidation(): void
    {
        if ($this->input('configuracao.tomador') !== null) {
            $configuracao = $this->input('configuracao');
            $configuracao['tomador'] = Pessoa::normalizar($configuracao['tomador']);
            $this->merge(['configuracao' => $configuracao]);
        }
    }

    public function rules(): array
    {
        if ($this->isMethod('PATCH')) {
            return ['ativa' => ['required', 'boolean']];
        }

        $regras = Transportadoras::LISTA[$this->route('transportadora')]::regras();

        // Ao editar, quem nao manda "credenciais" mantem as que ja estao salvas (o segredo nunca volta pela API).
        if (! $this->has('credenciais') && $this->jaCadastrada()) {
            $regras = array_filter($regras, fn ($regra, $campo) => ! str_starts_with($campo, 'credenciais'), ARRAY_FILTER_USE_BOTH);
        }

        return $regras + ['ativa' => ['sometimes', 'boolean']];
    }

    public function jaCadastrada(): bool
    {
        return $this->attributes->get('cliente_api')->credenciais()->where('transportadora', $this->route('transportadora'))->exists();
    }
}
