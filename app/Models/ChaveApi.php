<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Chave de API de um cliente. Do segredo existe so o hash. */
class ChaveApi extends Model
{
    use HasUuids;

    protected $table = 'chaves_api';

    protected $fillable = ['nome', 'prefixo', 'hash_segredo', 'ultimo_uso_em', 'revogada_em'];

    protected $hidden = ['hash_segredo'];

    protected function casts(): array
    {
        return ['ultimo_uso_em' => 'datetime', 'revogada_em' => 'datetime'];
    }

    /** Chaves que ainda funcionam (nao revogadas). */
    public function scopeAtivas(Builder $query): Builder
    {
        return $query->whereNull('revogada_em');
    }
}
