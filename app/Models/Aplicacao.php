<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma tela do portal (COT_01...). Catalogo global, vem de config/permissoes.php. */
class Aplicacao extends Model
{
    protected $table = 'aplicacoes';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['codigo', 'modulo_codigo', 'nome', 'caminho', 'acoes', 'ordem'];

    protected function casts(): array
    {
        return ['acoes' => 'array'];
    }
}
