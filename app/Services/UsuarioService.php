<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PapelUsuario;
use App\Models\Cliente;
use App\Models\Usuario;
use Illuminate\Support\Facades\Log;

/** Usuarios do portal. O dono (adm_tenant) cadastra os operadores do proprio cliente. */
class UsuarioService
{
    /** Cria um operador (operador_tenant) no cliente. Um dono so e criado junto com o cliente (ClienteService). */
    public function criarOperador(Cliente $cliente, array $dados): Usuario
    {
        $operador = $cliente->usuarios()->create([
            'nome' => $dados['nome'],
            'email' => $dados['email'],
            'password' => $dados['password'],
            'papel' => PapelUsuario::OperadorTenant,
            'ativo' => true,
        ]);

        Log::info('Operador criado', ['cliente_id' => $cliente->id, 'usuario_id' => $operador->id]);

        return $operador;
    }

    /** Atualiza um operador: nome, ativo (bloquear/liberar) e/ou password (redefinir). */
    public function atualizarOperador(Usuario $operador, array $dados): Usuario
    {
        $operador->update($dados);

        Log::info('Operador atualizado', ['usuario_id' => $operador->id, 'campos' => array_keys($dados)]);

        return $operador;
    }
}
