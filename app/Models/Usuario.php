<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PapelUsuario;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Pessoa que entra no portal (e-mail e senha). Pertence a um cliente. */
class Usuario extends Authenticatable
{
    use HasUuids;

    protected $table = 'usuarios';

    protected $fillable = ['nome', 'email', 'password', 'papel', 'ativo', 'ultimo_login_em'];

    // A senha (hash) nunca sai em JSON/logs.
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'papel' => PapelUsuario::class,
            'ativo' => 'boolean',
            'password' => 'hashed', // ao gravar, vira hash automaticamente
            'ultimo_login_em' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    /** Permissoes do operador por aplicacao (o dono nao tem linhas: pode tudo). */
    public function permissoes(): HasMany
    {
        return $this->hasMany(UsuarioAplicacao::class, 'usuario_id')->orderBy('aplicacao_codigo');
    }

    public function ehDono(): bool
    {
        return $this->papel === PapelUsuario::AdmTenant;
    }
}
