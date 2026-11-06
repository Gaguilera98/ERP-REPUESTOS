<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes_compra', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('proveedor_id');
            $table->foreignId('proveedor_contacto_id')->nullable();
            $table->foreignId('user_id');
            $table->foreignId('almacen_destino_id');
            $table->boolean('es_compra_flash')->default(false);
            $table->enum('estado', ['pendiente', 'recibida_parcial', 'completada', 'anulada'])->default('pendiente');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('total', 10, 2);
            $table->string('persona_entrega_nombre')->nullable();
            $table->foreignId('persona_recibe_trabajador_id')->nullable();
            $table->timestamp('fecha_emision');
            $table->timestamp('fecha_recepcion')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'proveedor_id'])
                ->references(['tenant_id', 'id'])
                ->on('proveedores');
            $table->foreign(['tenant_id', 'proveedor_contacto_id'])
                ->references(['tenant_id', 'id'])
                ->on('proveedor_contactos');
            $table->foreign(['tenant_id', 'user_id'])
                ->references(['tenant_id', 'id'])
                ->on('users');
            $table->foreign(['tenant_id', 'almacen_destino_id'])
                ->references(['tenant_id', 'id'])
                ->on('almacenes');
            $table->foreign(['tenant_id', 'persona_recibe_trabajador_id'])
                ->references(['tenant_id', 'id'])
                ->on('trabajadores');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_compra');
    }
};
