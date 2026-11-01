<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_detalles', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('venta_id');
            $table->foreignId('producto_variante_id');
            $table->foreignId('unidad_medida_id');
            $table->decimal('factor_a_base', 18, 6);
            $table->decimal('cantidad', 14, 4);
            $table->decimal('cantidad_base', 14, 4);
            $table->decimal('precio_unitario', 10, 2);
            $table->decimal('precio_costo_snapshot', 14, 4);
            $table->enum('tipo_descuento', ['porcentaje', 'importe'])->nullable();
            $table->decimal('valor_descuento', 10, 2)->default(0);
            $table->decimal('descuento_aplicado', 10, 2)->default(0);
            $table->foreignId('supervisor_descuento_id')->nullable();
            $table->decimal('total_linea', 10, 2);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'venta_id'])
                ->references(['tenant_id', 'id'])
                ->on('ventas')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'producto_variante_id'])
                ->references(['tenant_id', 'id'])
                ->on('productos_variantes');
            $table->foreign(['tenant_id', 'unidad_medida_id'])
                ->references(['tenant_id', 'id'])
                ->on('unidades_medida');
            $table->foreign(['tenant_id', 'supervisor_descuento_id'])
                ->references(['tenant_id', 'id'])
                ->on('users');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            Schema::getConnection()->statement(<<<'SQL'
                alter table venta_detalles
                add constraint venta_detalles_descuento_check
                check (
                    descuento_aplicado <= (precio_unitario * cantidad_base)
                    and (
                        tipo_descuento is distinct from 'porcentaje'
                        or valor_descuento <= 100
                    )
                )
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_detalles');
    }
};
