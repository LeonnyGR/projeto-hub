<?php

declare(strict_types=1);

use App\Services\CatalogoDePermissoes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalogo (igual para todos os clientes; vem de config/permissoes.php).
        Schema::create('modulos', function (Blueprint $table) {
            $table->string('codigo', 8)->primary(); // COT, OPE...
            $table->string('nome', 60);
            $table->string('icone', 60);
            $table->unsignedSmallInteger('ordem');
        });

        Schema::create('aplicacoes', function (Blueprint $table) {
            $table->string('codigo', 12)->primary(); // COT_01...
            $table->string('modulo_codigo', 8);
            $table->string('nome', 80);
            $table->string('caminho', 120)->unique(); // rota da pagina no front
            $table->jsonb('acoes'); // acoes que a aplicacao aceita
            $table->unsignedSmallInteger('ordem');

            $table->foreign('modulo_codigo')->references('codigo')->on('modulos')->cascadeOnDelete();
        });

        // O que cada operador pode fazer em cada aplicacao. O dono (adm_tenant) nao tem linhas: pode tudo.
        Schema::create('usuario_aplicacoes', function (Blueprint $table) {
            $table->id();
            $table->uuid('usuario_id');
            $table->string('aplicacao_codigo', 12);
            $table->jsonb('acoes');
            $table->timestampsTz();

            $table->foreign('usuario_id')->references('id')->on('usuarios')->cascadeOnDelete();
            $table->foreign('aplicacao_codigo')->references('codigo')->on('aplicacoes')->cascadeOnDelete();
            $table->unique(['usuario_id', 'aplicacao_codigo']);
        });

        app(CatalogoDePermissoes::class)->sincronizar();
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_aplicacoes');
        Schema::dropIfExists('aplicacoes');
        Schema::dropIfExists('modulos');
    }
};
