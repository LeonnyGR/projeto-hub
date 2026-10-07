<?php

declare(strict_types=1);

namespace App\Http\Requests;

class CotacaoRequest extends ApiFormRequest
{
    /** CEP pode vir com hifen ("01310-100"): guardamos so os numeros. */
    protected function prepareForValidation(): void
    {
        foreach (['cep_origem', 'cep_destino'] as $campo) {
            $cep = is_string($this->input($campo)) ? preg_replace('/\D/', '', $this->input($campo)) : '';

            // Sem nenhum numero ("abc") mantemos o valor original para a validacao reclamar dele.
            if ($cep !== '') {
                $this->merge([$campo => $cep]);
            }
        }
    }

    public function rules(): array
    {
        $max = config('frete.entrada');

        return [
            // Opcional: sem ele, vale o cep_origem cadastrado no seller.
            'cep_origem' => ['nullable', 'string', 'regex:/^\d{8}$/'],
            'cep_destino' => ['required', 'string', 'regex:/^\d{8}$/'],
            'valor_declarado_centavos' => ['required', 'integer', 'min:0', 'max:'.$max['max_valor_declarado_centavos']],
            'volumes' => ['required', 'array', 'min:1', 'max:'.$max['max_volumes']],
            'volumes.*' => ['array'],
            'volumes.*.peso_gramas' => ['required', 'integer', 'min:1', 'max:'.$max['max_peso_gramas']],
            'volumes.*.altura_cm' => ['required', 'integer', 'min:1', 'max:'.$max['max_dimensao_cm']],
            'volumes.*.largura_cm' => ['required', 'integer', 'min:1', 'max:'.$max['max_dimensao_cm']],
            'volumes.*.comprimento_cm' => ['required', 'integer', 'min:1', 'max:'.$max['max_dimensao_cm']],
        ];
    }

    /** So os campos que conhecemos dentro de cada volume (nada extra vai para as transportadoras). */
    public function validated($key = null, $default = null)
    {
        $dados = parent::validated();
        $dados['volumes'] = array_map(fn (array $v) => array_intersect_key($v, array_flip(['peso_gramas', 'altura_cm', 'largura_cm', 'comprimento_cm'])), $dados['volumes']);

        return $key === null ? $dados : data_get($dados, $key, $default);
    }
}
