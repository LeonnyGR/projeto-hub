<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->char('cep_origem', 8)->nullable();
        });

        // Sellers que ja existiam ficam com um CEP provisorio (Av. Paulista, SP): corrija com PATCH /sellers/{id}.
        DB::table('sellers')->whereNull('cep_origem')->update(['cep_origem' => '01310100']);

        Schema::table('sellers', function (Blueprint $table) {
            $table->char('cep_origem', 8)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropColumn('cep_origem');
        });
    }
};
