<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('cliente_id')->nullable();
            $table->foreignId('caja_sesion_id')->nullable();
            $table->enum('sentido', ['cobro', 'reembolso'])->default('cobro');
            $table->string('metodo_pago');
            $table->decimal('monto', 10, 2);
            $table->string('comprobante_archivo_path')->nullable();
            $table->string('numero_referencia_banco')->nullable();
            $table->string('qr_id_transaccion_banco')->nullable();
            $table->enum('estado_pago', ['pendiente', 'aprobado', 'expirado'])->default('aprobado');
            $table->timestamp('fecha_pago');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'cliente_id'])
                ->references(['tenant_id', 'id'])
                ->on('clientes');
            $table->foreign(['tenant_id', 'caja_sesion_id'])
                ->references(['tenant_id', 'id'])
                ->on('cajas_sesiones');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            Schema::getConnection()->statement(<<<'SQL'
                alter table pagos
                add constraint pagos_monto_check
                check (monto > 0)
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
