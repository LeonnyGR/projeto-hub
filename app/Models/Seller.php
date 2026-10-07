<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\ErroDeNegocio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Seller extends Model
{
    use HasUuids;

    protected $table = 'sellers';

    protected $fillable = ['nome', 'codigo_externo', 'cep_origem', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    /** Seller desabilitado nao cota nem cria remessa (mas ainda pode ser consultado). */
    public function exigirAtivo(): void
    {
        if (! $this->ativo) {
            throw new ErroDeNegocio('seller_desabilitado', 'Este seller esta desabilitado.', 409);
        }
    }

    /** Cliente da API (B2C) que cadastrou este seller. */
    public function clienteApi(): BelongsTo
    {
        return $this->belongsTo(ClienteApi::class, 'cliente_api_id');
    }

    /**
     * Contratos com as transportadoras que este seller usa: sao os do cliente da API (B2C) dono dele.
     * (Nao e uma relacao do Eloquent; devolve uma consulta: ->where(...)->first(), ->get()...)
     */
    public function credenciais(): Builder
    {
        return CredencialTransportadora::query()->where('cliente_api_id', $this->cliente_api_id);
    }

    public function cotacoes(): HasMany
    {
        return $this->hasMany(Cotacao::class, 'seller_id');
    }

    /** Envios e devolucoes. */
    public function remessas(): HasMany
    {
        return $this->hasMany(Remessa::class, 'seller_id');
    }
}
