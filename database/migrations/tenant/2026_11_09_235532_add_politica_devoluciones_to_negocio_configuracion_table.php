<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('negocio_configuracion', function (Blueprint $table): void {
            $table->boolean('acepta_devoluciones')->default(false);
            $table->unsignedSmallInteger('dias_devolucion')->default(0);
            $table->boolean('devolucion_permite_cambio')->default(true);
            $table->boolean('devolucion_permite_saldo_favor')->default(false);
            $table->boolean('devolucion_permite_reembolso')->default(false);
            $table->boolean('devolucion_permite_excepcion')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('negocio_configuracion', function (Blueprint $table): void {
            $table->dropColumn(['acepta_devoluciones', 'dias_devolucion', 'devolucion_permite_cambio', 'devolucion_permite_saldo_favor', 'devolucion_permite_reembolso', 'devolucion_permite_excepcion']);
        });
    }
};
