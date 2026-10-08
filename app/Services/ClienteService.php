<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PapelUsuario;
use App\Models\Cliente;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Cadastro de clientes (feito pela administracao). */
class ClienteService
{
    /**
     * Cria o cliente e o usuario dono dele (adm_tenant), que depois cria os operadores, as chaves de API etc.
     *
     * @param  array  $dono  nome, email e password do primeiro usuario
     * @return array  [Cliente, Usuario]
     */
    public function criar(string $nome, string $cnpj, array $dono): array
    {
        return DB::transaction(function () use ($nome, $cnpj, $dono) {
            $cliente = Cliente::create(['nome' => $nome, 'cnpj' => $cnpj, 'ativo' => true]);

            $usuario = $cliente->usuarios()->create([
                'nome' => $dono['nome'],
                'email' => $dono['email'],
                'password' => $dono['password'],
                'papel' => PapelUsuario::AdmTenant,
                'ativo' => true,
            ]);

            Log::info('Cliente criado', ['cliente_id' => $cliente->id]);

            return [$cliente, $usuario];
        });
    }
}
