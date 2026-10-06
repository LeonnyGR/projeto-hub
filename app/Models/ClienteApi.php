<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sistema que chama a API (hoje, o B2C). E dele: as chaves de API, o contrato com cada
 * transportadora (usado por todos os sellers dele) e os sellers que ele cadastrou.
 */
class ClienteApi extends Model
{
    use HasUuids;

    protected $table = 'clientes_api';

    protected $fillable = ['nome', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function chaves(): HasMany
    {
        return $this->hasMany(ChaveApi::class, 'cliente_api_id');
    }

    public function credenciais(): HasMany
    {
        return $this->hasMany(CredencialTransportadora::class, 'cliente_api_id');
    }

    public function sellers(): HasMany
    {
        return $this->hasMany(Seller::class, 'cliente_api_id');
    }
}
