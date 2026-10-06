<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ErroDeNegocio;
use App\Models\Seller;
use App\Transportadoras\PontosDeRetirada;
use App\Transportadoras\TransportadoraIndisponivel;
use App\Transportadoras\Transportadoras;

/** Consultas simples: endereco por CEP (ViaCEP) e pontos de retirada (de quem oferecer). */
class ConsultasService
{
    public function __construct(private readonly ViaCep $viaCep)
    {
    }

    /** CEP nao depende de seller nem de contrato. */
    public function endereco(string $cep): array
    {
        return $this->viaCep->buscar($cep);
    }

    /**
     * Pontos de retirada perto de um CEP, de todas as transportadoras ativas que oferecem isso
     * (ou so da informada em $transportadora). Se uma falhar, as outras continuam; a falha vai em "falhas".
     *
     * @return array  [pontos, falhas]
     */
    public function pontosDeRetirada(Seller $seller, string $cep, ?string $tipos, ?string $transportadora): array
    {
        $credenciais = $seller->credenciais()->where('ativa', true)
            ->when($transportadora !== null, fn ($consulta) => $consulta->where('transportadora', $transportadora))
            ->orderBy('transportadora')->get();

        if ($transportadora !== null && $credenciais->isEmpty()) {
            throw new ErroDeNegocio('transportadora_nao_configurada', "O seller nao tem a transportadora '{$transportadora}' configurada.", 422);
        }

        $pontos = [];
        $falhas = [];
        $consultadas = 0;

        foreach ($credenciais as $credencial) {
            $oferece = Transportadoras::criar($credencial, $seller->id);

            // Nem toda transportadora oferece pontos de retirada: quem nao oferece e ignorada.
            if (! $oferece instanceof PontosDeRetirada) {
                if ($transportadora !== null) {
                    throw new ErroDeNegocio('recurso_nao_suportado', "A transportadora '{$transportadora}' nao oferece pontos de retirada.", 422);
                }

                continue;
            }

            $consultadas++;

            try {
                foreach ($oferece->pontosDeRetirada($cep, $tipos) as $ponto) {
                    $pontos[] = ['transportadora' => $oferece->codigo()] + $ponto;
                }
            } catch (TransportadoraIndisponivel $e) {
                $falhas[] = ['transportadora' => $oferece->codigo(), 'motivo' => $e->motivo];
            }
        }

        if ($consultadas === 0) {
            throw new ErroDeNegocio('sem_pontos_de_retirada', 'Nenhuma transportadora ativa oferece pontos de retirada.', 422);
        }

        return [$pontos, $falhas];
    }
}
