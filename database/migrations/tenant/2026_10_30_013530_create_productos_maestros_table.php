<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos_maestros', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('nombre');
            $table->foreignId('categoria_id');
            $table->foreignId('marca_id')->nullable();
            $table->foreignId('unidad_base_id')->nullable();
            $table->boolean('is_active')->default(true);

            // Galería de imágenes del producto (almacenadas en S3 / disco configurado)
            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->jsonb('imagenes')->nullable();
            } else {
                $table->json('imagenes')->nullable();
            }

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'categoria_id'])
                ->references(['tenant_id', 'id'])
                ->on('categorias');
            $table->foreign(['tenant_id', 'marca_id'])
                ->references(['tenant_id', 'id'])
                ->on('marcas');
            $table->foreign(['tenant_id', 'unidad_base_id'])
                ->references(['tenant_id', 'id'])
                ->on('unidades_medida');
        });

        Schema::create('producto_unidades', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('producto_maestro_id');
            $table->foreignId('unidad_medida_id');
            $table->decimal('factor_a_base', 18, 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'producto_maestro_id', 'unidad_medida_id']);
            $table->foreign(['tenant_id', 'producto_maestro_id'])
                ->references(['tenant_id', 'id'])
                ->on('productos_maestros')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'unidad_medida_id'])
                ->references(['tenant_id', 'id'])
                ->on('unidades_medida');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_unidades');
        Schema::dropIfExists('productos_maestros');
    }
};
