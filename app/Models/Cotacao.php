<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Cotacao extends Model
{
    use HasUuids;

    protected $table = 'cotacoes';

    protected $fillable = ['cep_origem', 'cep_destino', 'requisicao', 'resultados', 'expira_em'];

    protected function casts(): array
    {
        return [
            'requisicao' => 'array',
            'resultados' => 'array',
            'expira_em' => 'datetime',
        ];
    }

    public function expirada(): bool
    {
        return $this->expira_em->isPast();
    }
}
