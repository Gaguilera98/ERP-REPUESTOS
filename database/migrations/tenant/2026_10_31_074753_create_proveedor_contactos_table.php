<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedor_contactos', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('proveedor_id');
            $table->string('nombres');
            $table->string('apellidos');
            $table->string('cargo')->nullable();
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
            $table->boolean('es_principal')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'proveedor_id'])
                ->references(['tenant_id', 'id'])
                ->on('proveedores')
                ->cascadeOnDelete();
        });

        $driver = Schema::getConnection()->getDriverName();
        $principal = match ($driver) {
            'pgsql' => 'es_principal IS TRUE',
            'sqlite' => 'es_principal = 1',
            default => throw new RuntimeException("El índice de contacto principal no está definido para {$driver}."),
        };

        Schema::getConnection()->statement(
            "CREATE UNIQUE INDEX proveedor_contactos_un_principal ON proveedor_contactos (tenant_id, proveedor_id) WHERE {$principal}",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedor_contactos');
    }
};
