<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Pessoa;
use App\Transportadoras\Transportadoras;

/**
 * Cadastrar/editar (PUT) ou ligar/desligar (PATCH) uma transportadora do cliente.
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

        // Ao editar, campo de credencial em branco significa "nao mudar": o formulario mostra o valor mascarado
        // (o segredo nunca volta pela API) e so manda o que a pessoa digitou de novo.
        if ($this->jaCadastrada() && is_array($this->input('credenciais'))) {
            $this->merge(['credenciais' => array_filter($this->input('credenciais'), fn ($valor) => $valor !== null && $valor !== '')]);
        }
    }

    public function rules(): array
    {
        if ($this->isMethod('PATCH')) {
            return ['ativa' => ['required', 'boolean']];
        }

        $regras = Transportadoras::LISTA[$this->route('transportadora')]::regras();

        // Ao editar, as credenciais sao opcionais, inclusive campo a campo: o que nao vier mantem o valor salvo.
        // (No primeiro cadastro todas continuam obrigatorias.)
        if ($this->jaCadastrada()) {
            foreach ($regras as $campo => $regra) {
                if (str_starts_with($campo, 'credenciais')) {
                    $regras[$campo] = array_map(fn ($item) => $item === 'required' ? 'sometimes' : $item, $regra);
                }
            }
        }

        return $regras + ['ativa' => ['sometimes', 'boolean']];
    }

    public function jaCadastrada(): bool
    {
        return $this->attributes->get('cliente')->credenciais()->where('transportadora', $this->route('transportadora'))->exists();
    }
}
