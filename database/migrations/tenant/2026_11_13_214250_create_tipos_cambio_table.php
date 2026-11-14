<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISO = 'Publicar:TipoCambio';

    public function up(): void
    {
        Schema::create('tipos_cambio', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('moneda_local', 3);
            $table->decimal('valor', 10, 4);
            $table->foreignId('user_id');
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'moneda_local', 'id']);
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users');
        });

        $conexion = Schema::getConnection();
        if ($conexion->getDriverName() === 'pgsql') {
            $conexion->statement('alter table tipos_cambio add constraint tipos_cambio_valor_positivo check (valor > 0)');
        }

        $this->cambiarReferenciaCompra(true);

        $db = DB::connection($this->getConnection());
        $db->table('permissions')->insertOrIgnore([
            'name' => self::PERMISO,
            'guard_name' => 'tenant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permiso = $db->table('permissions')->where('name', self::PERMISO)->where('guard_name', 'tenant')->value('id');
        foreach ($db->table('roles')->where('name', 'supervisor')->where('guard_name', 'tenant')->pluck('id') as $rol) {
            $db->table('role_has_permissions')->insertOrIgnore(['role_id' => $rol, 'permission_id' => $permiso]);
        }
    }

    public function down(): void
    {
        $this->cambiarReferenciaCompra(false);

        Schema::dropIfExists('tipos_cambio');

        DB::connection($this->getConnection())->table('permissions')
            ->where('name', self::PERMISO)->where('guard_name', 'tenant')->delete();
    }

    private function cambiarReferenciaCompra(bool $agregar): void
    {
        $conexion = Schema::getConnection();
        $triggers = $conexion->getDriverName() === 'sqlite'
            ? $conexion->table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'orden_compra_detalles')->pluck('sql')
            : [];

        Schema::table('orden_compra_detalles', function (Blueprint $table) use ($agregar): void {
            if ($agregar) {
                $table->foreign(['tenant_id', 'tipo_cambio_id'])->references(['tenant_id', 'id'])->on('tipos_cambio');
            } else {
                $table->dropForeign(['tenant_id', 'tipo_cambio_id']);
            }
        });

        foreach ($triggers as $sql) {
            $conexion->statement($sql);
        }
    }
};
