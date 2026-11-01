<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('nombre_empresa');
            $table->string('nit')->nullable();
            $table->boolean('es_ocasional')->default(false);
            $table->text('direccion')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'nit']);
        });

        $driver = Schema::getConnection()->getDriverName();
        $ocasional = match ($driver) {
            'pgsql' => 'es_ocasional IS TRUE',
            'sqlite' => 'es_ocasional = 1',
            default => throw new RuntimeException("El índice de proveedor ocasional no está definido para {$driver}."),
        };

        Schema::getConnection()->statement(
            "CREATE UNIQUE INDEX proveedores_un_ocasional ON proveedores (tenant_id) WHERE {$ocasional}",
        );

        if ($driver === 'pgsql') {
            Schema::getConnection()->statement(<<<'SQL'
                create function proveedores_ocasional_protegido() returns trigger
                language plpgsql
                as $$
                begin
                    if old.es_ocasional and (not new.es_ocasional or new.nombre_empresa <> 'Compra Ocasional / Proveedor Local') then
                        raise exception 'el proveedor ocasional no se modifica';
                    end if;

                    return new;
                end;
                $$
            SQL);
            Schema::getConnection()->statement(
                'create trigger proveedores_ocasional_no_update before update on proveedores for each row execute procedure proveedores_ocasional_protegido()'
            );
        }

        if ($driver === 'sqlite') {
            Schema::getConnection()->statement(<<<'SQL'
                create trigger proveedores_ocasional_no_update
                before update on proveedores
                for each row
                when old.es_ocasional = 1
                    and (new.es_ocasional = 0 or new.nombre_empresa <> 'Compra Ocasional / Proveedor Local')
                begin
                    select raise(abort, 'el proveedor ocasional no se modifica');
                end
            SQL);
        }

        if (Schema::hasTable('negocio_configuracion')) {
            $conexion = Schema::getConnection();
            $ahora = now();

            foreach ($conexion->table('negocio_configuracion')->pluck('tenant_id') as $tenantId) {
                $yaExiste = $conexion->table('proveedores')
                    ->where('tenant_id', $tenantId)
                    ->where('es_ocasional', true)
                    ->exists();

                if ($yaExiste) {
                    continue;
                }

                $conexion->table('proveedores')->insert([
                    'tenant_id' => $tenantId,
                    'nombre_empresa' => 'Compra Ocasional / Proveedor Local',
                    'es_ocasional' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('drop function if exists proveedores_ocasional_protegido()');
        }
    }
};
