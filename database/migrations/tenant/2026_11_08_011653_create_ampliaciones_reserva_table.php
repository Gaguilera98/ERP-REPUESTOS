<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ampliaciones_reserva', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('venta_id');
            $table->foreignId('user_id');
            $table->timestamp('vencimiento_anterior');
            $table->timestamp('vencimiento_nuevo');
            $table->text('motivo');
            $table->timestamps();
            $table->index(['tenant_id', 'venta_id', 'id']);
            $table->foreign(['tenant_id', 'venta_id'])->references(['tenant_id', 'id'])->on('ventas');
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users');
        });
        $db = DB::connection($this->getConnection());
        $db->table('permissions')->insertOrIgnore(['name' => 'Ampliar:Reserva', 'guard_name' => 'tenant']);
        $permiso = $db->table('permissions')->where('name', 'Ampliar:Reserva')->where('guard_name', 'tenant')->value('id');
        foreach ($db->table('roles')->where('name', 'supervisor')->where('guard_name', 'tenant')->pluck('id') as $rol) {
            $db->table('role_has_permissions')->insertOrIgnore(['role_id' => $rol, 'permission_id' => $permiso]);
        }
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->table('permissions')->where('name', 'Ampliar:Reserva')->where('guard_name', 'tenant')->delete();
        Schema::dropIfExists('ampliaciones_reserva');
    }
};
