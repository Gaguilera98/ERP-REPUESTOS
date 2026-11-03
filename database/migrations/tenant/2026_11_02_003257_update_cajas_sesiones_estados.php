<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("alter table cajas_sesiones modify estado_sesion enum('abierta', 'pendiente_arqueo', 'cerrada', 'anulada') not null default 'abierta'");
        }

        if ($driver !== 'pgsql') {
            return;
        }

        DB::statement('alter table cajas_sesiones drop constraint if exists cajas_sesiones_estado_sesion_check');
        DB::statement(<<<'SQL'
            alter table cajas_sesiones
            add constraint cajas_sesiones_estado_sesion_check
            check (estado_sesion in ('abierta', 'pendiente_arqueo', 'cerrada', 'anulada'))
        SQL);

        DB::statement('alter table cajas_sesiones drop constraint if exists cajas_sesiones_cierre_check');
        DB::statement(<<<'SQL'
            alter table cajas_sesiones
            add constraint cajas_sesiones_cierre_check
            check (
                (estado_sesion in ('abierta', 'pendiente_arqueo') and fecha_cierre is null)
                or (estado_sesion in ('cerrada', 'anulada') and fecha_cierre is not null)
            )
        SQL);
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'pgsql') {
            return;
        }

        DB::statement('alter table cajas_sesiones drop constraint if exists cajas_sesiones_estado_sesion_check');
        DB::statement(<<<'SQL'
            alter table cajas_sesiones
            add constraint cajas_sesiones_estado_sesion_check
            check (estado_sesion in ('abierta', 'cerrada'))
        SQL);

        DB::statement('alter table cajas_sesiones drop constraint if exists cajas_sesiones_cierre_check');
        DB::statement(<<<'SQL'
            alter table cajas_sesiones
            add constraint cajas_sesiones_cierre_check
            check (
                (estado_sesion = 'abierta' and fecha_cierre is null)
                or (estado_sesion = 'cerrada' and fecha_cierre is not null)
            )
        SQL);
    }
};
