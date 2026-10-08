<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente do hub (o lojista ou o B2C). Cada cliente e um "tenant": e dele o contrato com cada
 * transportadora, as cotacoes e as remessas. Quem entra na API usa uma chave de API dele; quem entra no portal e um usuario dele.
 */
class Cliente extends Model
{
    use HasUuids;

    protected $table = 'clientes';

    protected $fillable = ['nome', 'cnpj', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function chaves(): HasMany
    {
        return $this->hasMany(ChaveApi::class, 'cliente_id');
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'cliente_id');
    }

    /** Contratos deste cliente com as transportadoras. */
    public function credenciais(): HasMany
    {
        return $this->hasMany(CredencialTransportadora::class, 'cliente_id');
    }

    public function cotacoes(): HasMany
    {
        return $this->hasMany(Cotacao::class, 'cliente_id');
    }

    /** Envios e devolucoes. */
    public function remessas(): HasMany
    {
        return $this->hasMany(Remessa::class, 'cliente_id');
    }
}
