<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table): void {
            $table->decimal('efectivo_recibido', 10, 2)->nullable();
            $table->decimal('cambio_entregado', 10, 2)->nullable();
        });
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(<<<'SQL'
                alter table pagos add constraint pagos_efectivo_cambio_check check (
                    (efectivo_recibido is null and cambio_entregado is null)
                    or (
                        efectivo_recibido is not null and cambio_entregado is not null
                        and metodo_pago = 'efectivo' and sentido = 'cobro'
                        and cambio_entregado >= 0 and efectivo_recibido >= monto
                        and efectivo_recibido - cambio_entregado = monto
                    )
                )
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('alter table pagos drop constraint pagos_efectivo_cambio_check');
        }
        Schema::table('pagos', function (Blueprint $table): void {
            $table->dropColumn(['efectivo_recibido', 'cambio_entregado']);
        });
    }
};
