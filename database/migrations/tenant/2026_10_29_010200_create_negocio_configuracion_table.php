<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('negocio_configuracion', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedInteger('horas_reserva')->default(48);
            $table->decimal('descuento_max_porcentaje', 5, 2)->default(10);
            $table->decimal('descuento_max_importe', 10, 2)->default(0);
            $table->enum('moneda', ['BOB', 'PEN', 'BRL'])->default('BOB');
            $table->enum('modo_moneda', ['solo_moneda', 'con_dolar'])->default('solo_moneda');
            $table->unsignedBigInteger('siguiente_numero_venta')->default(1);
            $table->timestamps();

            $table->unique('tenant_id');
            $table->unique(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('negocio_configuracion');
    }
};
