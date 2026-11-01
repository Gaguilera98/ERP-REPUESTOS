<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_inventario', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('producto_variante_id');
            $table->foreignId('almacen_id');
            $table->foreignId('user_id');
            $table->enum('tipo_movimiento', [
                'entrada_compra',
                'salida_venta',
                'devolucion',
                'traslado_salida',
                'traslado_entrada',
                'ajuste',
                'entrada_flash_emergencia',
                'correccion_costo',
            ]);
            $table->decimal('cantidad', 14, 4);
            $table->decimal('stock_previo', 14, 4);
            $table->decimal('stock_posterior', 14, 4);
            $table->decimal('costo_unitario', 14, 4);
            $table->decimal('costo_promedio_resultante', 14, 4);
            $table->string('referencia_tipo')->nullable();
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'producto_variante_id'])
                ->references(['tenant_id', 'id'])
                ->on('productos_variantes');
            $table->foreign(['tenant_id', 'almacen_id'])
                ->references(['tenant_id', 'id'])
                ->on('almacenes');
            $table->foreign(['tenant_id', 'user_id'])
                ->references(['tenant_id', 'id'])
                ->on('users');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            Schema::getConnection()->statement('alter table movimientos_inventario add constraint movimientos_inventario_stock_check check (stock_previo >= 0 and stock_posterior >= 0)');
            Schema::getConnection()->statement(<<<'SQL'
                create function movimientos_inventario_solo_insercion() returns trigger
                language plpgsql
                as $$
                begin
                    raise exception 'el kardex solo se inserta';
                end;
                $$
            SQL);
            Schema::getConnection()->statement(
                'create trigger movimientos_inventario_no_update before update on movimientos_inventario for each row execute procedure movimientos_inventario_solo_insercion()'
            );
        }

        if ($driver === 'sqlite') {
            Schema::getConnection()->statement(<<<'SQL'
                create trigger movimientos_inventario_stock_insert
                before insert on movimientos_inventario
                for each row
                when new.stock_previo < 0 or new.stock_posterior < 0
                begin
                    select raise(abort, 'stock de kardex inválido');
                end
            SQL);
            Schema::getConnection()->statement(<<<'SQL'
                create trigger movimientos_inventario_no_update
                before update on movimientos_inventario
                for each row
                begin
                    select raise(abort, 'el kardex solo se inserta');
                end
            SQL);
            // El delete no se bloquea acá: al borrar el negocio hay que limpiar el kardex del pool.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('drop function if exists movimientos_inventario_solo_insercion()');
        }
    }
};
