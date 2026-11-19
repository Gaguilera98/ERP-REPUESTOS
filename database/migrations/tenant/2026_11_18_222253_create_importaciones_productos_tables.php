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
        $this->actualizarTipos(true);
        Schema::create('importaciones_productos', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('user_id');
            $table->foreignId('almacen_id')->nullable();
            $table->string('destino');
            $table->string('huella', 64);
            $table->string('archivo');
            $table->string('moneda', 3);
            $table->string('estado')->default('revision');
            $table->boolean('actualizar_existentes')->default(false);
            $table->date('fecha_inventario')->nullable();
            $table->json('filas');
            $table->json('revision');
            $table->json('resultado')->nullable();
            $table->timestamp('completada_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'destino', 'huella'], 'importaciones_productos_archivo_unique');
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'almacen_id'])->references(['tenant_id', 'id'])->on('almacenes');
        });

        Schema::create('inventario_inicial_detalles', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('importacion_id');
            $table->foreignId('producto_variante_id');
            $table->foreignId('proveedor_id')->nullable();
            $table->unsignedInteger('fila');
            $table->decimal('cantidad', 14, 4);
            $table->decimal('costo_unitario', 14, 4);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'importacion_id', 'fila'], 'inventario_inicial_fila_unique');
            $table->foreign(['tenant_id', 'importacion_id'])->references(['tenant_id', 'id'])->on('importaciones_productos');
            $table->foreign(['tenant_id', 'producto_variante_id'])->references(['tenant_id', 'id'])->on('productos_variantes');
            $table->foreign(['tenant_id', 'proveedor_id'])->references(['tenant_id', 'id'])->on('proveedores');
        });

        $db = DB::connection($this->getConnection());
        foreach (['Importar:Productos', 'Cargar:InventarioInicial'] as $nombre) {
            $db->table('permissions')->insertOrIgnore(['name' => $nombre, 'guard_name' => 'tenant']);
            $permiso = $db->table('permissions')->where('name', $nombre)->where('guard_name', 'tenant')->value('id');
            foreach ($db->table('roles')->whereIn('name', ['administrador', 'supervisor'])->where('guard_name', 'tenant')->pluck('id') as $rol) {
                $db->table('role_has_permissions')->insertOrIgnore(['role_id' => $rol, 'permission_id' => $permiso]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->actualizarTipos(false);
        Schema::dropIfExists('inventario_inicial_detalles');
        Schema::dropIfExists('importaciones_productos');
    }

    private function actualizarTipos(bool $incluirInicial): void
    {
        $tipos = ['entrada_compra', 'salida_venta', 'devolucion', 'traslado_salida', 'traslado_entrada', 'ajuste', 'entrada_flash_emergencia', 'correccion_costo'];
        if ($incluirInicial) {
            $tipos[] = 'inventario_inicial';
        }
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::connection($this->getConnection())->statement('ALTER TABLE movimientos_inventario DROP CONSTRAINT IF EXISTS movimientos_inventario_tipo_movimiento_check');
            $lista = "'".implode("','", $tipos)."'";
            DB::connection($this->getConnection())->statement('ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_inventario_tipo_movimiento_check CHECK (tipo_movimiento IN ('.$lista.'))');
        } else {
            Schema::table('movimientos_inventario', function (Blueprint $table) use ($tipos): void {
                $table->enum('tipo_movimiento', $tipos)->change();
            });
        }
    }
};
