<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CredencialTransportadora extends Model
{
    use HasUuids;

    protected $table = 'credenciais_transportadoras';

    protected $fillable = ['transportadora', 'ativa', 'credenciais', 'configuracao'];

    // Segredos nunca saem em JSON/logs.
    protected $hidden = ['credenciais'];

    protected function casts(): array
    {
        return [
            'ativa' => 'boolean',
            'credenciais' => 'encrypted:array',
            'configuracao' => 'array',
        ];
    }
}
