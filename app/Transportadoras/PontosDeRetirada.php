<?php

declare(strict_types=1);

namespace App\Transportadoras;

/**
 * Capacidade OPCIONAL: transportadora que sabe listar pontos de retirada (lockers, agencias, franquias...).
 * Nem toda transportadora tem isso (hoje so a Jadlog). Para oferecer, a classe da transportadora implementa
 * esta interface alem de `Transportadora`; o ConsultasService descobre sozinho quem a implementa.
 */
interface PontosDeRetirada
{
    /**
     * Pontos de retirada perto de um CEP.
     *
     * @param  string  $cep   8 digitos
     * @param  ?string $tipos tipos separados por virgula, como o seller informou (ex.: "LOCKER,PUBLICO").
     *                        Cada transportadora usa os tipos que conhece e ignora os outros; vazio = todos.
     * @return array  lista de ['id', 'nome', 'tipo_codigo', 'logradouro', 'numero', 'bairro', 'cidade', 'uf', 'cep', 'latitude', 'longitude']
     *
     * @throws TransportadoraIndisponivel se a transportadora nao respondeu direito
     */
    public function pontosDeRetirada(string $cep, ?string $tipos = null): array;
}
