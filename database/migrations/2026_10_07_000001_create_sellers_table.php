<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cliente da API = sistema que chama a API (hoje, o B2C). E dele:
        //  - as chaves de API;
        //  - o contrato com cada transportadora (tabela credenciais_transportadoras);
        //  - os sellers que ele cadastra.
        Schema::create('clientes_api', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nome', 120);
            $table->boolean('ativo')->default(true);
            $table->timestampsTz();
        });

        // Um cliente pode ter ate 2 chaves ativas (durante a troca de chave).
        // Guardamos so o hash do segredo e o prefixo (usado para achar a chave).
        Schema::create('chaves_api', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cliente_api_id');
            $table->string('prefixo', 32)->unique();
            $table->char('hash_segredo', 64);
            $table->timestampTz('expira_em')->nullable();
            $table->timestampTz('revogada_em')->nullable();
            $table->timestampTz('ultimo_uso_em')->nullable();
            $table->timestampsTz();

            $table->foreign('cliente_api_id')->references('id')->on('clientes_api')->cascadeOnDelete();
            $table->index(['cliente_api_id', 'revogada_em']);
        });

        // Seller = lojista cadastrado pelo cliente da API. Nao tem chave nem contrato proprio:
        // quem chama a API e o B2C, que informa o seller na URL de cada chamada.
        Schema::create('sellers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cliente_api_id');
            $table->string('nome', 120);
            // Identificador do seller no sistema que o cadastrou (B2C). Evita cadastrar o mesmo lojista duas vezes.
            $table->string('codigo_externo', 64)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestampsTz();

            $table->foreign('cliente_api_id')->references('id')->on('clientes_api')->cascadeOnDelete();
            $table->unique(['cliente_api_id', 'codigo_externo']);
            $table->index(['cliente_api_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
        Schema::dropIfExists('chaves_api');
        Schema::dropIfExists('clientes_api');
    }
};
