<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table): void {
            $table->boolean('sin_devolucion_comercial')->default(false);
        });

        Schema::table('productos_maestros', function (Blueprint $table): void {
            $table->string('devolucion_comercial')->default('hereda');
        });

        Schema::table('venta_detalles', function (Blueprint $table): void {
            $table->boolean('sin_devolucion_comercial')->default(false);
            $table->string('motivo_sin_devolucion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('venta_detalles', function (Blueprint $table): void {
            $table->dropColumn(['sin_devolucion_comercial', 'motivo_sin_devolucion']);
        });

        Schema::table('productos_maestros', function (Blueprint $table): void {
            $table->dropColumn('devolucion_comercial');
        });

        Schema::table('categorias', function (Blueprint $table): void {
            $table->dropColumn('sin_devolucion_comercial');
        });
    }
};
