<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $permisos = [
        'Cargar:Conteo',
        'Cerrar:Conteo',
        'Ajustar:Inventario',
        'Trasladar:Inventario',
        'Corregir:Costo',
    ];

    public function up(): void
    {
        Schema::create('ajustes_inventario', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('almacen_id');
            $table->foreignId('user_id');
            $table->foreignId('supervisor_id')->nullable();
            $table->enum('tipo', ['puntual', 'conteo']);
            $table->enum('estado', ['en_curso', 'cerrado']);
            $table->text('observacion')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'almacen_id', 'estado']);
            $table->foreign(['tenant_id', 'almacen_id'])->references(['tenant_id', 'id'])->on('almacenes');
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'supervisor_id'])->references(['tenant_id', 'id'])->on('users');
        });

        Schema::create('ajuste_detalles', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('ajuste_id');
            $table->foreignId('producto_variante_id');
            $table->enum('motivo', ['merma', 'rotura', 'hallazgo', 'uso_interno', 'diferencia_conteo']);
            $table->decimal('cantidad_sistema', 14, 4);
            $table->decimal('cantidad_fisica', 14, 4);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'ajuste_id', 'producto_variante_id']);
            $table->foreign(['tenant_id', 'ajuste_id'])->references(['tenant_id', 'id'])->on('ajustes_inventario')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'producto_variante_id'])->references(['tenant_id', 'id'])->on('productos_variantes');
        });

        Schema::create('traslados', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('almacen_origen_id');
            $table->foreignId('almacen_destino_id');
            $table->foreignId('user_id');
            $table->enum('estado', ['completado']);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'almacen_origen_id'])->references(['tenant_id', 'id'])->on('almacenes');
            $table->foreign(['tenant_id', 'almacen_destino_id'])->references(['tenant_id', 'id'])->on('almacenes');
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users');
        });

        Schema::create('traslado_detalles', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('traslado_id');
            $table->foreignId('producto_variante_id');
            $table->decimal('cantidad', 14, 4);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'traslado_id'])->references(['tenant_id', 'id'])->on('traslados')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'producto_variante_id'])->references(['tenant_id', 'id'])->on('productos_variantes');
        });

        Schema::create('correcciones_costo', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('orden_compra_detalle_id');
            $table->foreignId('producto_variante_id');
            $table->foreignId('almacen_id');
            $table->foreignId('user_id');
            $table->foreignId('supervisor_id');
            $table->decimal('costo_anterior', 14, 4);
            $table->decimal('costo_nuevo', 14, 4);
            $table->decimal('promedio_anterior', 14, 4);
            $table->decimal('promedio_nuevo', 14, 4);
            $table->text('observacion');
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'orden_compra_detalle_id'])->references(['tenant_id', 'id'])->on('orden_compra_detalles');
            $table->foreign(['tenant_id', 'producto_variante_id'])->references(['tenant_id', 'id'])->on('productos_variantes');
            $table->foreign(['tenant_id', 'almacen_id'])->references(['tenant_id', 'id'])->on('almacenes');
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'supervisor_id'])->references(['tenant_id', 'id'])->on('users');
        });

        $db = DB::connection($this->getConnection());

        foreach ($this->permisos as $nombre) {
            $db->table('permissions')->insertOrIgnore(['name' => $nombre, 'guard_name' => 'tenant']);
            $permiso = $db->table('permissions')->where('name', $nombre)->where('guard_name', 'tenant')->value('id');
            $roles = $nombre === 'Cargar:Conteo'
                ? $db->table('roles')->whereIn('name', ['supervisor', 'cajero'])->where('guard_name', 'tenant')->pluck('id')
                : $db->table('roles')->where('name', 'supervisor')->where('guard_name', 'tenant')->pluck('id');

            foreach ($roles as $rol) {
                $db->table('role_has_permissions')->insertOrIgnore(['role_id' => $rol, 'permission_id' => $permiso]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('correcciones_costo');
        Schema::dropIfExists('traslado_detalles');
        Schema::dropIfExists('traslados');
        Schema::dropIfExists('ajuste_detalles');
        Schema::dropIfExists('ajustes_inventario');
        DB::connection($this->getConnection())->table('permissions')->whereIn('name', $this->permisos)->where('guard_name', 'tenant')->delete();
    }
};
