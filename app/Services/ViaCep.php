<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ErroDeNegocio;
use App\Transportadoras\ClienteHttp;
use App\Transportadoras\TransportadoraIndisponivel;
use Illuminate\Support\Facades\Cache;

/**
 * Consulta de CEP no ViaCEP (https://viacep.com.br). Nao precisa de conta.
 * Respostas com sucesso ficam no cache por alguns dias, para nao chamar o ViaCEP (nem os Correios) sem necessidade.
 *
 * Resposta do ViaCEP: {"cep":"01001-000","logradouro":"...","complemento":"...","bairro":"...","localidade":"...","uf":"SP",...}
 * CEP que nao existe: HTTP 200 com {"erro": true}.
 */
class ViaCep
{
    public function __construct(private readonly ClienteHttp $http)
    {
    }

    /**
     * @param  string  $cep  8 digitos
     * @return array  cep, logradouro, complemento, bairro, cidade, uf
     *
     * @throws ErroDeNegocio 404 se o CEP nao existe; 502 se o ViaCEP nao respondeu direito
     */
    public function buscar(string $cep): array
    {

        $resposta = $this->http->enviar(null, 'viacep', 'cep', [
            'metodo' => 'GET',
            'url' => config('transportadoras.viacep.url').'/ws/'.$cep.'/json/',
        ]);

        return $resposta->json();

        $problema = TransportadoraIndisponivel::motivoDaResposta($resposta);
        $json = $resposta?->json();

        if ($problema !== null || ! $resposta->successful() || ! is_array($json)) {
            throw new ErroDeNegocio('servico_de_cep_indisponivel', 'O servico de CEP nao respondeu. Tente novamente.', 502, ['motivo' => $problema ?? 'resposta_invalida']);
        }

        if (isset($json['erro'])) {
            throw new ErroDeNegocio('cep_nao_encontrado', 'CEP nao encontrado.', 404);
        }

        $endereco = [
            'cep' => preg_replace('/\D/', '', (string) ($json['cep'] ?? $cep)),
            'logradouro' => $this->texto($json['logradouro'] ?? null),
            'complemento' => $this->texto($json['complemento'] ?? null),
            'bairro' => $this->texto($json['bairro'] ?? null),
            'cidade' => $this->texto($json['localidade'] ?? null),
            'uf' => $this->texto($json['uf'] ?? null),
        ];

       // Cache::put($chave, $endereco, now()->addDays((int) config('transportadoras.viacep.cache_dias')));

        return $endereco;
    }

    /** O ViaCEP devolve "" para campos sem valor (CEP geral de cidade pequena, por exemplo). */
    private function texto(mixed $valor): ?string
    {
        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}
