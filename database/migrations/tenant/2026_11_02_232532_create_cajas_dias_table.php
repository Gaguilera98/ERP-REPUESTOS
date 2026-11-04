<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas_dias', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('sucursal_id');
            $table->date('fecha');
            $table->foreignId('user_id');
            $table->timestamp('fecha_cierre');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sucursal_id', 'fecha']);
            $table->foreign(['tenant_id', 'sucursal_id'])
                ->references(['tenant_id', 'id'])
                ->on('sucursales');
            $table->foreign(['tenant_id', 'user_id'])
                ->references(['tenant_id', 'id'])
                ->on('users');
        });

        Schema::table('cajas_sesiones', function (Blueprint $table) {
            $table->foreignId('caja_dia_id')->nullable();
            $table->foreign(['tenant_id', 'caja_dia_id'])
                ->references(['tenant_id', 'id'])
                ->on('cajas_dias');
        });

        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement('drop index if exists cajas_sesiones_una_abierta');
        DB::statement("create unique index cajas_sesiones_una_abierta on cajas_sesiones (tenant_id, sucursal_id, user_id) where estado_sesion = 'abierta'");
    }

    public function down(): void
    {
        Schema::table('cajas_sesiones', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'caja_dia_id']);
            $table->dropColumn('caja_dia_id');
        });

        Schema::dropIfExists('cajas_dias');
    }
};
