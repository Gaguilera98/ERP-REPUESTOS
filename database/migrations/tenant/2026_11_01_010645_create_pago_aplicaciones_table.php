<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pago_aplicaciones', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('pago_id');
            $table->foreignId('venta_id')->nullable();
            $table->unsignedBigInteger('nota_credito_id')->nullable();
            $table->foreignId('aplicacion_origen_id')->nullable();
            $table->decimal('monto', 10, 2);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'pago_id'])
                ->references(['tenant_id', 'id'])
                ->on('pagos')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'venta_id'])
                ->references(['tenant_id', 'id'])
                ->on('ventas');
            $table->foreign(['tenant_id', 'aplicacion_origen_id'])
                ->references(['tenant_id', 'id'])
                ->on('pago_aplicaciones');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            Schema::getConnection()->statement(<<<'SQL'
                alter table pago_aplicaciones
                add constraint pago_aplicaciones_monto_check
                check (monto > 0)
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pago_aplicaciones');
    }
};
