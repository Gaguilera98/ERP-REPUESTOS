<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caja_sesion_medios', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('caja_sesion_id');
            $table->string('metodo_pago');
            $table->decimal('monto_esperado', 10, 2);
            $table->decimal('monto_declarado', 10, 2);
            $table->decimal('diferencia', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'caja_sesion_id', 'metodo_pago'], 'caja_sesion_medios_metodo_unico');
            $table->foreign(['tenant_id', 'caja_sesion_id'])
                ->references(['tenant_id', 'id'])
                ->on('cajas_sesiones')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caja_sesion_medios');
    }
};
