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
        'Registrar:GastoCajaPropia',
        'Registrar:GastoCajaAjena',
        'Operar:CajaChica',
        'Revisar:CajaChica',
        'Cerrar:TurnoAjeno',
        'Administrar:CategoriaGasto',
        'Administrar:FondoCajaChica',
        'View:CajaChica',
    ];

    public function up(): void
    {
        Schema::create('user_sucursales', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('user_id');
            $table->foreignId('sucursal_id');
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'user_id', 'sucursal_id']);
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'sucursal_id'])->references(['tenant_id', 'id'])->on('sucursales')->cascadeOnDelete();
        });

        Schema::table('negocio_configuracion', function (Blueprint $table): void {
            $table->boolean('permite_gastos_cajon_venta')->default(false);
        });

        Schema::table('cajas_sesiones', function (Blueprint $table): void {
            $table->foreignId('cerrado_por_user_id')->nullable();
            $table->foreignId('cierre_vales_autorizado_por_id')->nullable();
            $table->text('cierre_vales_motivo')->nullable();
            $table->foreign(['tenant_id', 'cerrado_por_user_id'])->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'cierre_vales_autorizado_por_id'])->references(['tenant_id', 'id'])->on('users');
        });

        Schema::create('categorias_gastos', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'nombre']);
        });

        Schema::create('caja_chica_fondos', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('sucursal_id');
            $table->foreignId('responsable_id');
            $table->decimal('monto_apertura', 10, 2);
            $table->decimal('saldo_actual', 10, 2);
            $table->string('estado')->default('activo');
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sucursal_id']);
            $table->foreign(['tenant_id', 'sucursal_id'])->references(['tenant_id', 'id'])->on('sucursales');
            $table->foreign(['tenant_id', 'responsable_id'])->references(['tenant_id', 'id'])->on('users');
        });

        Schema::create('caja_chica_arqueos', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('fondo_id');
            $table->string('tipo');
            $table->decimal('teorico', 10, 2);
            $table->decimal('contado', 10, 2);
            $table->decimal('diferencia', 10, 2)->default(0);
            $table->text('justificacion')->nullable();
            $table->foreignId('user_id');
            $table->foreignId('entrega_user_id')->nullable();
            $table->foreignId('recibe_user_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'fondo_id'])->references(['tenant_id', 'id'])->on('caja_chica_fondos');
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'entrega_user_id'])->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'recibe_user_id'])->references(['tenant_id', 'id'])->on('users');
        });

        Schema::create('caja_chica_movimientos', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('sucursal_id');
            $table->foreignId('caja_sesion_id')->nullable();
            $table->foreignId('fondo_id')->nullable();
            $table->foreignId('categoria_gasto_id')->nullable();
            $table->foreignId('registrado_por_user_id');
            $table->foreignId('rendido_por_user_id')->nullable();
            $table->foreignId('seguimiento_user_id')->nullable();
            $table->foreignId('autorizado_por_user_id')->nullable();
            $table->foreignId('orden_compra_id')->nullable();
            $table->foreignId('movimiento_origen_id')->nullable();
            $table->string('tipo');
            $table->string('sentido');
            $table->string('estado');
            $table->boolean('afecta_saldo')->default(true);
            $table->decimal('monto', 10, 2);
            $table->decimal('monto_gasto', 10, 2)->nullable();
            $table->decimal('monto_sobrante', 10, 2)->nullable();
            $table->string('concepto');
            $table->string('persona_recibe')->nullable();
            $table->string('procedencia')->nullable();
            $table->string('tipo_comprobante')->nullable();
            $table->string('numero_comprobante')->nullable();
            $table->string('comprobante_archivo_path')->nullable();
            $table->text('motivo_excepcion')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'caja_sesion_id', 'estado']);
            $table->index(['tenant_id', 'fondo_id', 'estado']);
            $table->foreign(['tenant_id', 'sucursal_id'])->references(['tenant_id', 'id'])->on('sucursales');
            $table->foreign(['tenant_id', 'caja_sesion_id'])->references(['tenant_id', 'id'])->on('cajas_sesiones');
            $table->foreign(['tenant_id', 'fondo_id'])->references(['tenant_id', 'id'])->on('caja_chica_fondos');
            $table->foreign(['tenant_id', 'categoria_gasto_id'])->references(['tenant_id', 'id'])->on('categorias_gastos');
            $table->foreign(['tenant_id', 'registrado_por_user_id'])->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'rendido_por_user_id'])->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'seguimiento_user_id'])->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'autorizado_por_user_id'])->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'orden_compra_id'])->references(['tenant_id', 'id'])->on('ordenes_compra');
        });

        Schema::table('caja_chica_movimientos', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'movimiento_origen_id'])->references(['tenant_id', 'id'])->on('caja_chica_movimientos');
        });

        Schema::getConnection()->statement(
            "CREATE UNIQUE INDEX caja_chica_un_sobrante ON caja_chica_movimientos (tenant_id, movimiento_origen_id) WHERE tipo = 'ingreso_sobrante'",
        );

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            Schema::getConnection()->statement('drop index if exists cajas_sesiones_una_abierta');
            Schema::getConnection()->statement(
                "create unique index cajas_sesiones_una_abierta on cajas_sesiones (tenant_id, sucursal_id, user_id) where estado_sesion = 'abierta'",
            );
        }

        $db = DB::connection($this->getConnection());
        $supervisor = $db->table('roles')->where('name', 'supervisor')->where('guard_name', 'tenant')->pluck('id');
        $cajero = $db->table('roles')->where('name', 'cajero')->where('guard_name', 'tenant')->pluck('id');
        $delCajero = ['Registrar:GastoCajaPropia', 'View:CajaChica'];

        foreach ($this->permisos as $nombre) {
            $db->table('permissions')->insertOrIgnore([
                'name' => $nombre,
                'guard_name' => 'tenant',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $permiso = $db->table('permissions')->where('name', $nombre)->where('guard_name', 'tenant')->value('id');
            $roles = in_array($nombre, $delCajero, true) ? $supervisor->merge($cajero) : $supervisor;

            foreach ($roles as $rol) {
                $db->table('role_has_permissions')->insertOrIgnore([
                    'role_id' => $rol,
                    'permission_id' => $permiso,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('caja_chica_movimientos');
        Schema::dropIfExists('caja_chica_arqueos');
        Schema::dropIfExists('caja_chica_fondos');
        Schema::dropIfExists('categorias_gastos');
        Schema::table('cajas_sesiones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cerrado_por_user_id');
            $table->dropConstrainedForeignId('cierre_vales_autorizado_por_id');
            $table->dropColumn('cierre_vales_motivo');
        });
        Schema::table('negocio_configuracion', function (Blueprint $table): void {
            $table->dropColumn('permite_gastos_cajon_venta');
        });
        Schema::dropIfExists('user_sucursales');

        $ids = DB::connection($this->getConnection())->table('permissions')->whereIn('name', $this->permisos)->pluck('id');
        DB::connection($this->getConnection())->table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::connection($this->getConnection())->table('permissions')->whereIn('id', $ids)->delete();
    }
};
