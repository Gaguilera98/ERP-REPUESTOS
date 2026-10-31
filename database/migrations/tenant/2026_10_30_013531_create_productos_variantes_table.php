<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos_variantes', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('producto_maestro_id');
            $table->string('sku');
            $table->string('codigo_barras')->nullable();
            $table->string('imagen')->nullable(); // Foto específica de la variante en S3 (opcional)
            $table->decimal('precio_venta', 10, 2);
            $table->decimal('precio_mayor', 10, 2)->nullable();
            $table->decimal('precio_venta_dolar', 10, 2)->nullable();
            $table->decimal('precio_mayor_dolar', 10, 2)->nullable();
            $table->decimal('precio_costo', 14, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // atributos_json guarda solo los valores de categoria_atributos.
            // Un decimal es número JSON. Un texto o una opción de lista es texto.
            // En PostgreSQL se usa jsonb + índice GIN para la igualdad.
            // En otros motores (SQLite en tests) queda como json sin GIN.
            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->jsonb('atributos_json')->default('{}');
            } else {
                $table->json('atributos_json')->default('{}');
            }

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sku']);
            $table->unique(['tenant_id', 'codigo_barras']);
            $table->foreign(['tenant_id', 'producto_maestro_id'])
                ->references(['tenant_id', 'id'])
                ->on('productos_maestros')
                ->cascadeOnDelete();
        });

        // Índice GIN sobre atributos_json solo en PostgreSQL.
        // La igualdad de uno o varios atributos se resuelve dentro del negocio
        // y de la categoría. El rango numérico no usa este índice.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'CREATE INDEX productos_variantes_atributos_gin ON productos_variantes USING gin (atributos_json)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('productos_variantes');
    }
};
