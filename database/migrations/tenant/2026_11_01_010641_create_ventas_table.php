<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('codigo');
            $table->foreignId('sucursal_id');
            $table->foreignId('almacen_id');
            $table->unsignedBigInteger('talonario_id')->nullable();
            $table->foreignId('cliente_id')->nullable();
            $table->foreignId('user_id');
            $table->enum('condicion', ['contado', 'credito'])->default('contado');
            $table->enum('estado', ['pendiente', 'completada', 'anulada', 'reserva', 'vencida'])->default('pendiente');
            $table->timestamp('vence_en')->nullable();
            $table->json('politica_devolucion_snapshot')->nullable();
            $table->timestamp('entregada_en')->nullable();
            $table->enum('tipo_comprobante', ['nota_venta', 'factura_manual']);
            $table->string('numero_comprobante')->nullable();
            $table->decimal('subtotal', 10, 2);
            $table->enum('tipo_descuento', ['porcentaje', 'importe'])->nullable();
            $table->decimal('valor_descuento', 10, 2)->default(0);
            $table->decimal('descuento', 10, 2)->default(0);
            $table->foreignId('supervisor_descuento_id')->nullable();
            $table->decimal('total', 10, 2);
            $table->text('notes_internas')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'codigo']);
            $table->unique(['tenant_id', 'sucursal_id', 'tipo_comprobante', 'numero_comprobante'], 'ventas_comprobante_unico');
            $table->foreign(['tenant_id', 'sucursal_id'])
                ->references(['tenant_id', 'id'])
                ->on('sucursales');
            $table->foreign(['tenant_id', 'almacen_id'])
                ->references(['tenant_id', 'id'])
                ->on('almacenes');
            $table->foreign(['tenant_id', 'sucursal_id', 'almacen_id'])
                ->references(['tenant_id', 'sucursal_id', 'almacen_id'])
                ->on('sucursal_almacenes');
            $table->foreign(['tenant_id', 'cliente_id'])
                ->references(['tenant_id', 'id'])
                ->on('clientes');
            $table->foreign(['tenant_id', 'user_id'])
                ->references(['tenant_id', 'id'])
                ->on('users');
            $table->foreign(['tenant_id', 'supervisor_descuento_id'])
                ->references(['tenant_id', 'id'])
                ->on('users');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            Schema::getConnection()->statement(<<<'SQL'
                alter table ventas
                add constraint ventas_factura_manual_check
                check (
                    tipo_comprobante <> 'factura_manual'
                    or (
                        cliente_id is not null
                        and numero_comprobante is not null
                        and talonario_id is not null
                    )
                )
            SQL);
            Schema::getConnection()->statement(<<<'SQL'
                alter table ventas
                add constraint ventas_reserva_vence_check
                check (
                    estado not in ('reserva', 'vencida')
                    or vence_en is not null
                )
            SQL);
            Schema::getConnection()->statement(<<<'SQL'
                alter table ventas
                add constraint ventas_descuento_check
                check (
                    descuento <= subtotal
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
        Schema::dropIfExists('ventas');
    }
};
