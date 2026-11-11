<?php

namespace App\Livewire\Negocio\Caja;

use App\Enums\CondicionVenta;
use App\Enums\EstadoVenta;
use App\Models\NegocioConfiguracion;
use App\Models\PagoAplicacion;
use App\Models\Venta;
use App\Ventas\HistorialDeVenta;
use App\Ventas\RegistrarDevolucion;
use App\Ventas\SaldoAFavorCliente;
use Filament\Actions\Action;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class AccionVerDetalleVenta
{
    public function crear(string $nombre = 'verDetalle'): Action
    {
        return Action::make($nombre)
            ->label('Ver detalle')
            ->icon(Heroicon::OutlinedEye)
            ->authorize(fn (?Venta $record): bool => $record !== null && (bool) auth('tenant')->user()?->can('view', $record))
            ->modalHeading(fn (Venta $record): string => $record->tipo_comprobante->etiqueta().' · '.$record->codigo)
            ->modalWidth('5xl')
            ->extraModalWindowAttributes(['class' => 'caja-modal-window'])
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->modalContent(fn (Venta $record): View => $this->vista($record));
    }

    private function vista(Venta $record): View
    {
        abort_unless(auth('tenant')->user()?->can('view', $record), 403);

        $record->loadMissing([
            'cliente',
            'usuario',
            'anuladaPor',
            'correcciones.usuario',
            'ultimaCorreccion',
            'devoluciones.detalles',
            'devoluciones.usuario',
            'sucursal',
            'almacen',
            'detalles.variante.productoMaestro.unidadBase',
            'detalles.unidad',
            'aplicacionesPago.pago.registradoPor',
            'aplicacionesPago.usos',
        ]);
        $reservaCancelada = $record->estado === EstadoVenta::Anulada && $record->aplicacionesPago->contains(
            fn (PagoAplicacion $aplicacion): bool => $aplicacion->usos->contains(
                fn (PagoAplicacion $uso): bool => $uso->venta_id === null && (int) $uso->reserva_saldo_origen_id === $record->id,
            ),
        );

        $ampliaciones = DB::table('ampliaciones_reserva as ampliacion')
            ->join('users as usuario', function (JoinClause $join): void {
                $join->on('usuario.id', '=', 'ampliacion.user_id')->on('usuario.tenant_id', '=', 'ampliacion.tenant_id');
            })->where('ampliacion.tenant_id', $record->tenant_id)->where('ampliacion.venta_id', $record->id)
            ->orderByDesc('ampliacion.id')->get(['ampliacion.*', 'usuario.name as usuario_nombre']);
        $moneda = NegocioConfiguracion::codigoMoneda();

        return view('filament.negocio.pages.caja.detalle-venta', [
            'venta' => $record,
            'productosTrasDevoluciones' => $record->devoluciones->isNotEmpty() ? app(RegistrarDevolucion::class)->disponibles($record) : [],
            'historialVenta' => app(HistorialDeVenta::class)->eventos($record, $ampliaciones, $moneda),
            'moneda' => $moneda,
            'saldoSenaDisponible' => $record->estado === EstadoVenta::Vencida || $reservaCancelada ? app(SaldoAFavorCliente::class)->disponibleDeReserva($record) : null,
            'saldoPendiente' => $record->condicion === CondicionVenta::Credito || $record->estado === EstadoVenta::Reserva ? $record->saldoPendiente() : null,
            'colorEstado' => self::colorEstado($record->estado),
        ]);
    }

    /** @return array<int, string> */
    public static function colorEstado(EstadoVenta $estado): array
    {
        return match ($estado) {
            EstadoVenta::Completada => Color::Emerald,
            EstadoVenta::Anulada, EstadoVenta::Vencida => Color::Red,
            EstadoVenta::Pendiente => Color::Amber,
            EstadoVenta::Reserva => Color::Blue,
        };
    }
}
