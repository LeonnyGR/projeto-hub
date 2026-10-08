<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Usuarios do portal: pessoas da empresa do cliente. Entram com e-mail e senha (cookie de sessao).
        //  - papel: adm_tenant (o dono do cliente) ou operador_tenant (funcionario);
        //  - e-mail unico no sistema inteiro, para o login nao precisar saber de qual cliente a pessoa e.
        Schema::create('usuarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cliente_id');
            $table->string('nome', 120);
            $table->string('email', 190)->unique();
            $table->string('password'); // hash (bcrypt/argon), nunca a senha
            $table->string('papel', 20);
            $table->boolean('ativo')->default(true);
            $table->timestampTz('ultimo_login_em')->nullable();
            $table->timestampsTz();

            $table->foreign('cliente_id')->references('id')->on('clientes')->cascadeOnDelete();
            $table->index(['cliente_id', 'papel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
