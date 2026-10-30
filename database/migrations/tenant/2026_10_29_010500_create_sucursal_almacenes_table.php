<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sucursal_almacenes', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('sucursal_id');
            $table->foreignId('almacen_id');
            $table->boolean('es_principal')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'sucursal_id', 'almacen_id']);
            $table->foreign(['tenant_id', 'sucursal_id'])
                ->references(['tenant_id', 'id'])
                ->on('sucursales')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'almacen_id'])
                ->references(['tenant_id', 'id'])
                ->on('almacenes')
                ->cascadeOnDelete();
        });

        $driver = Schema::getConnection()->getDriverName();
        $predicate = match ($driver) {
            'pgsql' => 'es_principal IS TRUE',
            'sqlite' => 'es_principal = 1',
            default => throw new RuntimeException("El índice de almacén principal no está definido para {$driver}."),
        };

        Schema::getConnection()->statement(
            "CREATE UNIQUE INDEX sucursal_almacenes_un_principal ON sucursal_almacenes (tenant_id, sucursal_id) WHERE {$predicate}",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sucursal_almacenes');
    }
};
