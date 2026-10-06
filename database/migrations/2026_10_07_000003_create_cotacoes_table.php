<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotacoes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('seller_id');
            $table->char('cep_origem', 8);
            $table->char('cep_destino', 8);
            $table->jsonb('requisicao');
            $table->jsonb('resultados');
            $table->timestampTz('expira_em');
            $table->timestampsTz();

            $table->foreign('seller_id')->references('id')->on('sellers')->cascadeOnDelete();
            // Permite a FK composta (seller_id, cotacao_id) da tabela remessas.
            $table->unique(['seller_id', 'id']);
            $table->index(['seller_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotacoes');
    }
};
