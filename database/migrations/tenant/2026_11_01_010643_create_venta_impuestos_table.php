<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_impuestos', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('venta_id');
            $table->string('nombre_impuesto_snapshot');
            $table->decimal('porcentaje_aplicado_snapshot', 5, 2);
            $table->decimal('monto_calculado', 10, 2);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'venta_id'])
                ->references(['tenant_id', 'id'])
                ->on('ventas')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_impuestos');
    }
};
