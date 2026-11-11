<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notas_credito_devoluciones', function (Blueprint $table): void {
            $table->string('resolucion')->nullable()->change();
            $table->foreignId('venta_reemplazo_id')->nullable();
            $table->unique(['tenant_id', 'venta_reemplazo_id'], 'devolucion_venta_reemplazo_unica');
            $table->foreign(['tenant_id', 'venta_reemplazo_id'], 'devolucion_venta_reemplazo_fk')->references(['tenant_id', 'id'])->on('ventas');
        });
        Schema::table('pago_aplicaciones', function (Blueprint $table): void {
            $table->boolean('es_compensacion_cambio')->default(false);
            $table->foreignId('cliente_saldo_id')->nullable();
            $table->foreign(['tenant_id', 'cliente_saldo_id'])->references(['tenant_id', 'id'])->on('clientes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('notas_credito_devoluciones')->whereNotNull('venta_reemplazo_id')->exists()) {
            throw new RuntimeException('Hay cambios de productos registrados. Revertir la migración eliminaría su trazabilidad.');
        }

        Schema::table('pago_aplicaciones', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'cliente_saldo_id']);
            $table->dropColumn(['es_compensacion_cambio', 'cliente_saldo_id']);
        });
        Schema::table('notas_credito_devoluciones', function (Blueprint $table): void {
            $table->dropForeign('devolucion_venta_reemplazo_fk');
            $table->dropUnique('devolucion_venta_reemplazo_unica');
            $table->dropColumn('venta_reemplazo_id');
            $table->string('resolucion')->change();
        });
    }
};
