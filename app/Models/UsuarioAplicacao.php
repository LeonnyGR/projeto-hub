<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** O que um operador pode fazer em uma aplicacao (ex.: OPE_02 => ver, etiqueta). */
class UsuarioAplicacao extends Model
{
    protected $table = 'usuario_aplicacoes';

    protected $fillable = ['usuario_id', 'aplicacao_codigo', 'acoes'];

    protected function casts(): array
    {
        return ['acoes' => 'array'];
    }
}
