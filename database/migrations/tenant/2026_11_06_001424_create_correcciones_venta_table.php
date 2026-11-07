<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correcciones_venta', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('venta_id');
            $table->foreignId('user_id');
            $table->foreignId('caja_sesion_id')->nullable();
            $table->text('motivo');
            $table->json('lineas');
            $table->decimal('total', 10, 2);
            $table->timestamps();

            $table->index(['tenant_id', 'venta_id', 'id']);
            $table->foreign(['tenant_id', 'venta_id'])->references(['tenant_id', 'id'])->on('ventas');
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'caja_sesion_id'])->references(['tenant_id', 'id'])->on('cajas_sesiones');
        });

        $db = DB::connection($this->getConnection());
        $db->table('permissions')->insertOrIgnore(['name' => 'Corregir:Venta', 'guard_name' => 'tenant']);
        $permiso = $db->table('permissions')->where('name', 'Corregir:Venta')->where('guard_name', 'tenant')->value('id');
        $roles = $db->table('roles')->where('name', 'supervisor')->where('guard_name', 'tenant')->pluck('id');
        $db->table('role_has_permissions')->insertOrIgnore($roles->map(fn ($id): array => [
            'role_id' => $id,
            'permission_id' => $permiso,
        ])->all());
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->table('permissions')->where('name', 'Corregir:Venta')->where('guard_name', 'tenant')->delete();
        Schema::dropIfExists('correcciones_venta');
    }
};
