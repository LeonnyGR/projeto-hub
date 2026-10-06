<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Registro de uma chamada HTTP a uma transportadora. Requisicao e resposta ja chegam mascaradas. */
class ChamadaTransportadora extends Model
{
    use HasUuids;

    protected $table = 'chamadas_transportadoras';

    protected $fillable = [
        'seller_id', 'transportadora', 'operacao', 'metodo', 'url', 'requisicao', 'resposta',
        'status_http', 'duracao_ms', 'erro', 'request_id',
    ];
}
