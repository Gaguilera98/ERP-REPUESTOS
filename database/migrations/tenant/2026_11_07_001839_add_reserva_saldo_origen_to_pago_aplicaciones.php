<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pago_aplicaciones', function (Blueprint $table): void {
            $table->foreignId('reserva_saldo_origen_id')->nullable();
            $table->foreign(['tenant_id', 'reserva_saldo_origen_id'])->references(['tenant_id', 'id'])->on('ventas');
        });
        $db = DB::connection($this->getConnection());
        $db->table('pago_aplicaciones')->whereNotNull('aplicacion_origen_id')
            ->chunkById(200, function ($aplicaciones) use ($db): void {
                foreach ($aplicaciones as $aplicacion) {
                    $origen = $db->table('pago_aplicaciones')->where('tenant_id', $aplicacion->tenant_id)
                        ->where('id', $aplicacion->aplicacion_origen_id)->first();
                    $reserva = $origen?->reserva_saldo_origen_id;
                    if ($reserva === null && $aplicacion->venta_id === null && $origen?->venta_id !== null) {
                        $reserva = $db->table('ventas')->where('tenant_id', $aplicacion->tenant_id)
                            ->where('id', $origen->venta_id)->where('estado', 'vencida')->value('id');
                    }
                    $db->table('pago_aplicaciones')->where('id', $aplicacion->id)
                        ->update(['reserva_saldo_origen_id' => $reserva]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('pago_aplicaciones', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'reserva_saldo_origen_id']);
            $table->dropColumn('reserva_saldo_origen_id');
        });
    }
};
