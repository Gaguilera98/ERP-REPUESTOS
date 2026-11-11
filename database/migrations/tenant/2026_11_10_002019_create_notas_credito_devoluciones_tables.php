<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notas_credito_devoluciones', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('venta_id');
            $table->foreignId('user_id');
            $table->foreignId('caja_sesion_id')->nullable();
            $table->text('motivo');
            $table->text('motivo_excepcion')->nullable();
            $table->enum('resolucion', ['reembolso', 'saldo_favor']);
            $table->decimal('total', 10, 2);
            $table->decimal('deuda_cancelada', 10, 2);
            $table->decimal('importe_resuelto', 10, 2);
            $table->string('revision', 64);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'revision']);
            $table->index(['tenant_id', 'venta_id', 'id']);
            $table->foreign(['tenant_id', 'venta_id'])->references(['tenant_id', 'id'])->on('ventas');
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'caja_sesion_id'])->references(['tenant_id', 'id'])->on('cajas_sesiones');
        });
        Schema::create('nota_credito_detalles', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('nota_credito_id');
            $table->foreignId('venta_detalle_id')->nullable();
            $table->unsignedInteger('linea_indice');
            $table->foreignId('producto_variante_id');
            $table->decimal('cantidad', 14, 4);
            $table->decimal('cantidad_base', 14, 4);
            $table->decimal('importe', 10, 2);
            $table->json('linea_snapshot');
            $table->timestamps();
            $table->unique(['tenant_id', 'nota_credito_id', 'linea_indice'], 'nota_credito_linea_unica');
            $table->foreign(['tenant_id', 'nota_credito_id'])->references(['tenant_id', 'id'])->on('notas_credito_devoluciones');
            $table->foreign(['tenant_id', 'venta_detalle_id'])->references(['tenant_id', 'id'])->on('venta_detalles');
            $table->foreign(['tenant_id', 'producto_variante_id'])->references(['tenant_id', 'id'])->on('productos_variantes');
        });
        Schema::table('pago_aplicaciones', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'nota_credito_id'])->references(['tenant_id', 'id'])->on('notas_credito_devoluciones');
        });
        $db = DB::connection($this->getConnection());
        foreach (['Registrar:Devolucion', 'Autorizar:ExcepcionDevolucion'] as $nombre) {
            $db->table('permissions')->insertOrIgnore(['name' => $nombre, 'guard_name' => 'tenant']);
            $permiso = $db->table('permissions')->where('name', $nombre)->where('guard_name', 'tenant')->value('id');
            foreach ($db->table('roles')->where('name', 'supervisor')->where('guard_name', 'tenant')->pluck('id') as $rol) {
                $db->table('role_has_permissions')->insertOrIgnore(['role_id' => $rol, 'permission_id' => $permiso]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pago_aplicaciones', fn (Blueprint $table) => $table->dropForeign(['tenant_id', 'nota_credito_id']));
        Schema::dropIfExists('nota_credito_detalles');
        Schema::dropIfExists('notas_credito_devoluciones');
        DB::connection($this->getConnection())->table('permissions')->whereIn('name', ['Registrar:Devolucion', 'Autorizar:ExcepcionDevolucion'])->where('guard_name', 'tenant')->delete();
    }
};
