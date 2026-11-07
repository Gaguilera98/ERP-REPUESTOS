<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('negocio_configuracion', function (Blueprint $table): void {
            $table->boolean('permite_reembolso_senas')->default(true);
        });
        Schema::table('ventas', function (Blueprint $table): void {
            $table->boolean('sena_reembolsable')->default(true);
        });
        Schema::table('pago_aplicaciones', function (Blueprint $table): void {
            $table->boolean('es_saldo_favor')->default(false);
            $table->index(['tenant_id', 'aplicacion_origen_id']);
        });
        Schema::table('pagos', function (Blueprint $table): void {
            $table->foreignId('registrado_por_id')->nullable();
            $table->text('motivo')->nullable();
            $table->foreign(['tenant_id', 'registrado_por_id'])->references(['tenant_id', 'id'])->on('users');
        });
        $db = DB::connection($this->getConnection());
        $db->table('permissions')->insertOrIgnore(['name' => 'Reembolsar:Sena', 'guard_name' => 'tenant']);
        $permiso = $db->table('permissions')->where('name', 'Reembolsar:Sena')->where('guard_name', 'tenant')->value('id');
        foreach ($db->table('roles')->where('name', 'supervisor')->where('guard_name', 'tenant')->pluck('id') as $rol) {
            $db->table('role_has_permissions')->insertOrIgnore(['permission_id' => $permiso, 'role_id' => $rol]);
        }
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->table('permissions')->where('name', 'Reembolsar:Sena')->where('guard_name', 'tenant')->delete();
        Schema::table('pagos', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'registrado_por_id']);
            $table->dropColumn(['registrado_por_id', 'motivo']);
        });
        Schema::table('pago_aplicaciones', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'aplicacion_origen_id']);
            $table->dropColumn('es_saldo_favor');
        });
        Schema::table('ventas', fn (Blueprint $table) => $table->dropColumn('sena_reembolsable'));
        Schema::table('negocio_configuracion', fn (Blueprint $table) => $table->dropColumn('permite_reembolso_senas'));
    }
};
