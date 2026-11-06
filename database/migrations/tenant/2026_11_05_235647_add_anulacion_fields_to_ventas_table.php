<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table): void {
            $table->foreignId('caja_sesion_id')->nullable();
            $table->foreignId('anulada_por_id')->nullable();
            $table->timestamp('anulada_en')->nullable();
            $table->text('motivo_anulacion')->nullable();

            $table->index(['tenant_id', 'caja_sesion_id']);
            $table->foreign(['tenant_id', 'caja_sesion_id'])->references(['tenant_id', 'id'])->on('cajas_sesiones');
            $table->foreign(['tenant_id', 'anulada_por_id'])->references(['tenant_id', 'id'])->on('users');
        });

        $conexion = DB::connection($this->getConnection());
        $conexion->table('ventas')->where('estado', 'completada')->chunkById(250, function ($ventas) use ($conexion): void {
            foreach ($ventas as $venta) {
                $sesiones = $conexion->table('cajas_sesiones')
                    ->where('tenant_id', $venta->tenant_id)
                    ->where('user_id', $venta->user_id)
                    ->where('sucursal_id', $venta->sucursal_id)
                    ->whereDate('fecha_apertura', substr($venta->created_at, 0, 10))
                    ->where('fecha_apertura', '<=', $venta->created_at)
                    ->where(fn ($query) => $query->whereNull('fecha_cierre')->orWhere('fecha_cierre', '>=', $venta->created_at))
                    ->limit(2)->pluck('id');

                if ($sesiones->count() === 1) {
                    $conexion->table('ventas')->where('id', $venta->id)->update(['caja_sesion_id' => $sesiones->first()]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'caja_sesion_id']);
            $table->dropForeign(['tenant_id', 'anulada_por_id']);
            $table->dropIndex(['tenant_id', 'caja_sesion_id']);
            $table->dropColumn(['caja_sesion_id', 'anulada_por_id', 'anulada_en', 'motivo_anulacion']);
        });
    }
};
