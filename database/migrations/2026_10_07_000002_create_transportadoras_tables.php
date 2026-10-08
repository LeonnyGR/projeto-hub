<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contrato do cliente com cada transportadora (token Jadlog, cartao de postagem Correios...).
        // Cada cliente cadastra o seu: a cotacao e a remessa dele sempre usam os dados dele.
        Schema::create('credenciais_transportadoras', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cliente_id');
            $table->string('transportadora', 32); // jadlog | correios
            $table->boolean('ativa')->default(true);
            // Dados secretos (token, senha). Criptografados com o APP_KEY.
            $table->text('credenciais');
            // Dados nao secretos: servicos contratados, dados do tomador do frete (quem paga)...
            $table->jsonb('configuracao')->default('{}');
            $table->timestampsTz();

            $table->foreign('cliente_id')->references('id')->on('clientes')->cascadeOnDelete();
            $table->unique(['cliente_id', 'transportadora']);
        });

        // Historico das chamadas as transportadoras, com dados sensiveis mascarados.
        Schema::create('chamadas_transportadoras', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cliente_id')->nullable();
            $table->string('transportadora', 32);
            $table->string('operacao', 40);
            $table->string('metodo', 10);
            $table->string('url', 300); // sem query string
            $table->text('requisicao')->nullable();
            $table->text('resposta')->nullable();
            $table->unsignedSmallInteger('status_http')->nullable();
            $table->unsignedInteger('duracao_ms')->nullable();
            $table->string('erro', 300)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->index(['cliente_id', 'created_at']);
            $table->index('created_at'); // para apagar chamadas antigas
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chamadas_transportadoras');
        Schema::dropIfExists('credenciais_transportadoras');
    }
};
