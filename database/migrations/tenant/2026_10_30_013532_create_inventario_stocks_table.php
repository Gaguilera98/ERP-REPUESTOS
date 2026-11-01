<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventario_stocks', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('producto_variante_id');
            $table->foreignId('almacen_id');
            $table->string('ubicacion')->nullable();
            $table->decimal('cantidad_actual', 14, 4)->default(0);
            $table->decimal('cantidad_reservada', 14, 4)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'producto_variante_id', 'almacen_id']);
            $table->foreign(['tenant_id', 'producto_variante_id'])
                ->references(['tenant_id', 'id'])
                ->on('productos_variantes')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'almacen_id'])
                ->references(['tenant_id', 'id'])
                ->on('almacenes')
                ->cascadeOnDelete();
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            Schema::getConnection()->statement('alter table inventario_stocks add constraint inventario_stocks_cantidad_actual_check check (cantidad_actual >= 0)');
            Schema::getConnection()->statement('alter table inventario_stocks add constraint inventario_stocks_cantidad_reservada_check check (cantidad_reservada >= 0)');
            Schema::getConnection()->statement('alter table inventario_stocks add constraint inventario_stocks_reservada_no_supera_check check (cantidad_reservada <= cantidad_actual)');
        }

        if ($driver === 'sqlite') {
            Schema::getConnection()->statement(<<<'SQL'
                create trigger inventario_stocks_cantidades_insert
                before insert on inventario_stocks
                for each row
                when new.cantidad_actual < 0
                    or new.cantidad_reservada < 0
                    or new.cantidad_reservada > new.cantidad_actual
                begin
                    select raise(abort, 'stock inválido');
                end
            SQL);
            Schema::getConnection()->statement(<<<'SQL'
                create trigger inventario_stocks_cantidades_update
                before update on inventario_stocks
                for each row
                when new.cantidad_actual < 0
                    or new.cantidad_reservada < 0
                    or new.cantidad_reservada > new.cantidad_actual
                begin
                    select raise(abort, 'stock inválido');
                end
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_stocks');
    }
};
