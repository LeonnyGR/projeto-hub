<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Envio e devolucao ficam na mesma tabela (coluna "tipo"): a devolucao e so uma remessa no sentido contrario.
        Schema::create('remessas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('seller_id');
            $table->string('tipo', 10); // envio | devolucao
            $table->uuid('cotacao_id')->nullable();        // so no envio
            $table->uuid('remessa_origem_id')->nullable(); // so na devolucao
            $table->string('motivo', 200)->nullable();     // so na devolucao
            $table->string('idempotency_key', 128);
            // Resumo do pedido. Mesma chave com pedido diferente => erro 422.
            $table->char('hash_requisicao', 64);
            $table->string('transportadora', 32);
            $table->string('servico_codigo', 40);
            $table->string('status', 30);
            $table->string('id_externo', 64)->nullable();
            $table->string('codigo_rastreio', 64)->nullable();
            $table->unsignedInteger('valor_frete_centavos')->nullable(); // vazio na devolucao (valor definido no contrato)
            $table->unsignedSmallInteger('prazo_dias')->nullable();
            // Dados pessoais criptografados (LGPD).
            $table->text('remetente');
            $table->text('destinatario');
            $table->jsonb('volumes');
            // Etiqueta fica no Storage (disco configuravel), nunca no banco.
            $table->string('etiqueta_caminho', 300)->nullable();
            $table->timestampTz('despachada_em')->nullable();
            $table->timestampTz('cancelada_em')->nullable();
            $table->timestampsTz();

            $table->foreign('seller_id')->references('id')->on('sellers')->cascadeOnDelete();
            $table->unique(['seller_id', 'id']);
            $table->foreign(['seller_id', 'cotacao_id'])->references(['seller_id', 'id'])->on('cotacoes');
            $table->foreign(['seller_id', 'remessa_origem_id'])->references(['seller_id', 'id'])->on('remessas');

            $table->unique(['seller_id', 'idempotency_key']);
            $table->index('codigo_rastreio');
            $table->index(['seller_id', 'status']);
            $table->index(['seller_id', 'created_at']);
            $table->index('status'); // o rastreio varre as remessas ativas de todos os sellers
        });

        // Historico de rastreio. O acesso e sempre pela remessa (que ja e do seller).
        Schema::create('eventos_rastreio', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('remessa_id');
            $table->string('status', 30);
            $table->string('descricao', 300)->nullable();
            $table->string('local', 120)->nullable();
            $table->timestampTz('ocorrido_em');
            $table->timestampsTz();

            $table->foreign('remessa_id')->references('id')->on('remessas')->cascadeOnDelete();
            // Evita gravar o mesmo evento duas vezes.
            $table->unique(['remessa_id', 'status', 'ocorrido_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_rastreio');
        Schema::dropIfExists('remessas');
    }
};
