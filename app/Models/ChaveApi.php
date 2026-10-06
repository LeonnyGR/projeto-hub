<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ChaveApi extends Model
{
    use HasUuids;

    protected $table = 'chaves_api';

    protected $fillable = ['prefixo', 'hash_segredo', 'expira_em', 'revogada_em', 'ultimo_uso_em'];

    protected $hidden = ['hash_segredo'];

    protected function casts(): array
    {
        return [
            'expira_em' => 'datetime',
            'revogada_em' => 'datetime',
            'ultimo_uso_em' => 'datetime',
        ];
    }

    /** Chaves que ainda funcionam: nao revogadas e nao expiradas. */
    public function scopeAtivas(Builder $query): Builder
    {
        return $query->whereNull('revogada_em')
            ->where(fn (Builder $q) => $q->whereNull('expira_em')->orWhere('expira_em', '>', now()));
    }
}
