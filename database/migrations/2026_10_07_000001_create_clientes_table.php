<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cliente = o lojista (ou o B2C) que usa o hub. Cada cliente e um "tenant": tudo que existe no
        // sistema (transportadoras, cotacoes, remessas, usuarios, chaves) pertence a um cliente e so ele enxerga.
        //  - cnpj = CNPJ do cliente (so numeros, unico: nao existem dois clientes com o mesmo).
        Schema::create('clientes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nome', 120);
            $table->char('cnpj', 14)->unique();
            $table->boolean('ativo')->default(true);
            $table->timestampsTz();
        });

        // Chaves de API: o backend do cliente (ex.: o B2C) chama a API com uma chave.
        // A chave tem a forma "<prefixo>.<segredo>". O prefixo (publico) acha a chave no banco e, por ela,
        // o cliente. Do segredo guardamos so o hash: o texto completo aparece uma unica vez, quando a chave e criada.
        Schema::create('chaves_api', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cliente_id');
            $table->string('nome', 60); // ex.: "producao", "homologacao"
            $table->string('prefixo', 32)->unique();
            $table->char('hash_segredo', 64);
            $table->timestampTz('ultimo_uso_em')->nullable();
            $table->timestampTz('revogada_em')->nullable();
            $table->timestampsTz();

            $table->foreign('cliente_id')->references('id')->on('clientes')->cascadeOnDelete();
            $table->index(['cliente_id', 'revogada_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chaves_api');
        Schema::dropIfExists('clientes');
    }
};
