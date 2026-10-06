<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EventoRastreio extends Model
{
    use HasUuids;

    protected $table = 'eventos_rastreio';

    protected $fillable = ['status', 'descricao', 'local', 'ocorrido_em'];

    protected function casts(): array
    {
        return ['ocorrido_em' => 'datetime'];
    }
}
