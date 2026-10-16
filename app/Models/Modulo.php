<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grupo de telas do portal (COT, OPE...). Catalogo global, vem de config/permissoes.php. */
class Modulo extends Model
{
    protected $table = 'modulos';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['codigo', 'nome', 'icone', 'ordem'];

    public function aplicacoes(): HasMany
    {
        return $this->hasMany(Aplicacao::class, 'modulo_codigo')->orderBy('ordem');
    }
}
