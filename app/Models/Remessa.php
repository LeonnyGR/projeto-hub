<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatusRemessa;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Um envio ou uma devolucao (coluna "tipo"). */
class Remessa extends Model
{
    use HasUuids;

    protected $table = 'remessas';

    protected $fillable = [
        'tipo', 'cotacao_id', 'remessa_origem_id', 'motivo', 'idempotency_key', 'hash_requisicao',
        'transportadora', 'servico_codigo', 'status', 'id_externo', 'codigo_rastreio',
        'valor_frete_centavos', 'prazo_dias', 'remetente', 'destinatario', 'volumes',
        'etiqueta_caminho', 'despachada_em', 'cancelada_em',
    ];

    protected $hidden = ['hash_requisicao'];

    protected function casts(): array
    {
        return [
            'status' => StatusRemessa::class,
            'valor_frete_centavos' => 'integer',
            'prazo_dias' => 'integer',
            'remetente' => 'encrypted:array',
            'destinatario' => 'encrypted:array',
            'volumes' => 'array',
            'despachada_em' => 'datetime',
            'cancelada_em' => 'datetime',
        ];
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoRastreio::class, 'remessa_id');
    }
}
