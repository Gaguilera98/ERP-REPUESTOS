<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orden_compra_detalles', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('orden_compra_id');
            $table->foreignId('producto_variante_id');
            $table->foreignId('unidad_medida_id')->nullable();
            $table->decimal('factor_a_base', 18, 6)->default(1);
            $table->decimal('cantidad_pedida', 14, 4);
            $table->decimal('cantidad_recibida', 14, 4)->default(0);
            $table->decimal('cantidad_base', 14, 4)->default(0);
            $table->decimal('costo_anotado', 14, 4)->nullable();
            $table->enum('moneda', ['local', 'USD'])->default('local');
            $table->decimal('costo_unitario_origen', 14, 4);
            // La foreign key a tipos_cambio llega con la fase del dólar. Hasta entonces el id queda vacío.
            $table->unsignedBigInteger('tipo_cambio_id')->nullable();
            $table->decimal('tipo_cambio_valor', 10, 4)->nullable();
            $table->decimal('costo_unitario', 14, 4);
            $table->decimal('subtotal_linea', 10, 2);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'orden_compra_id'])
                ->references(['tenant_id', 'id'])
                ->on('ordenes_compra')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'producto_variante_id'])
                ->references(['tenant_id', 'id'])
                ->on('productos_variantes');
            $table->foreign(['tenant_id', 'unidad_medida_id'])
                ->references(['tenant_id', 'id'])
                ->on('unidades_medida');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            Schema::getConnection()->statement('alter table orden_compra_detalles add constraint orden_compra_detalles_cantidad_pedida_check check (cantidad_pedida > 0)');
            Schema::getConnection()->statement('alter table orden_compra_detalles add constraint orden_compra_detalles_cantidad_recibida_check check (cantidad_recibida >= 0 and cantidad_recibida <= cantidad_pedida)');
            Schema::getConnection()->statement('alter table orden_compra_detalles add constraint orden_compra_detalles_costo_check check (costo_unitario >= 0 and costo_unitario_origen >= 0)');
        }

        if ($driver === 'sqlite') {
            Schema::getConnection()->statement(<<<'SQL'
                create trigger orden_compra_detalles_cantidades_insert
                before insert on orden_compra_detalles
                for each row
                when new.cantidad_pedida <= 0
                    or new.cantidad_recibida < 0
                    or new.cantidad_recibida > new.cantidad_pedida
                    or new.costo_unitario < 0
                    or new.costo_unitario_origen < 0
                begin
                    select raise(abort, 'detalle de compra inválido');
                end
            SQL);
            Schema::getConnection()->statement(<<<'SQL'
                create trigger orden_compra_detalles_cantidades_update
                before update on orden_compra_detalles
                for each row
                when new.cantidad_pedida <= 0
                    or new.cantidad_recibida < 0
                    or new.cantidad_recibida > new.cantidad_pedida
                    or new.costo_unitario < 0
                    or new.costo_unitario_origen < 0
                begin
                    select raise(abort, 'detalle de compra inválido');
                end
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_compra_detalles');
    }
};
