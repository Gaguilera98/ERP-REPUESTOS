<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas_sesiones', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('sucursal_id');
            $table->foreignId('user_id');
            $table->enum('estado_sesion', ['abierta', 'pendiente_arqueo', 'cerrada', 'anulada'])->default('abierta');
            $table->timestamp('fecha_apertura');
            $table->timestamp('fecha_cierre')->nullable();
            $table->decimal('monto_apertura', 10, 2);
            $table->decimal('monto_cierre_teorico', 10, 2)->nullable();
            $table->decimal('monto_cierre_real', 10, 2)->nullable();
            $table->decimal('diferencia', 10, 2)->default(0);
            $table->text('justificacion_diferencia')->nullable();
            $table->foreignId('supervisor_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'sucursal_id'])
                ->references(['tenant_id', 'id'])
                ->on('sucursales');
            $table->foreign(['tenant_id', 'user_id'])
                ->references(['tenant_id', 'id'])
                ->on('users');
            $table->foreign(['tenant_id', 'supervisor_id'])
                ->references(['tenant_id', 'id'])
                ->on('users');
        });

        $driver = Schema::getConnection()->getDriverName();
        $abierta = match ($driver) {
            'pgsql' => "estado_sesion = 'abierta'",
            'sqlite' => "estado_sesion = 'abierta'",
            default => throw new RuntimeException("El índice de caja abierta no está definido para {$driver}."),
        };

        Schema::getConnection()->statement(
            "CREATE UNIQUE INDEX cajas_sesiones_una_abierta ON cajas_sesiones (tenant_id, sucursal_id, user_id) WHERE {$abierta}",
        );

        if ($driver === 'pgsql') {
            Schema::getConnection()->statement(<<<'SQL'
                alter table cajas_sesiones
                add constraint cajas_sesiones_cierre_check
                check (
                    (estado_sesion in ('abierta', 'pendiente_arqueo') and fecha_cierre is null)
                    or (estado_sesion in ('cerrada', 'anulada') and fecha_cierre is not null)
                )
            SQL);
            Schema::getConnection()->statement(<<<'SQL'
                alter table cajas_sesiones
                add constraint cajas_sesiones_diferencia_check
                check (
                    diferencia = 0
                    or (justificacion_diferencia is not null and btrim(justificacion_diferencia) <> '')
                )
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cajas_sesiones');
    }
};
