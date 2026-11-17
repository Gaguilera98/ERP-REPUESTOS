<?php

namespace App\Http\Controllers;

use App\Enums\CondicionVenta;
use App\Enums\EstadoPago;
use App\Enums\EstadoVenta;
use App\Enums\MetodoPago;
use App\Enums\SentidoPago;
use App\Models\NegocioConfiguracion;
use App\Models\PagoAplicacion;
use App\Models\Venta;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ImprimirNotaVenta extends Controller
{
    public function __invoke(Request $request, Venta $venta): Response
    {
        Gate::forUser($request->user('tenant'))->authorize('viewInHistory', $venta);
        abort_unless($venta->tieneNotaImprimible(), 404);

        $venta->load([
            'cliente', 'usuario', 'sucursal', 'ultimaCorreccion',
            'detalles.unidad', 'detalles.variante.productoMaestro.unidadBase',
        ])->loadExists('devoluciones');

        $hayCambios = $venta->ultimaCorreccion !== null || $venta->devoluciones_exists;
        $anulada = $venta->estado === EstadoVenta::Anulada;
        $totalVigente = $hayCambios && ! $anulada ? $venta->totalVigente() : null;
        $cobros = collect();
        $pagado = $saldo = null;

        if (! $anulada && $venta->condicion === CondicionVenta::Credito) {
            $pagado = $venta->cobradoNeto();
            $saldo = bcsub($totalVigente ?? $venta->total, $pagado, 2);
        } elseif (! $anulada && ! $hayCambios) {
            $cobros = $venta->aplicacionesPago()->whereNull('aplicacion_origen_id')
                ->whereHas('pago', fn (Builder $query): Builder => $query
                    ->where('estado_pago', EstadoPago::Aprobado)->where('sentido', SentidoPago::Cobro))
                ->with('pago')->get()
                ->filter(fn (PagoAplicacion $aplicacion): bool => $aplicacion->pago->metodo_pago === MetodoPago::Efectivo
                    && $aplicacion->pago->efectivo_recibido !== null
                    && bccomp($aplicacion->monto, $aplicacion->pago->monto, 2) === 0);
        }

        $paraPdf = $request->routeIs('filament.negocio.ventas.nota-a4-pdf');
        $nombreArchivo = 'nota-'.Str::slug($venta->codigo).'.pdf';
        $vista = 'filament.negocio.documentos.nota-venta';
        $datos = [
            'titulo' => 'Nota de venta · '.$venta->codigo,
            'negocio' => tenant()->name,
            'venta' => $venta,
            'moneda' => NegocioConfiguracion::codigoMoneda(),
            'hayCambios' => $hayCambios,
            'totalVigente' => $totalVigente,
            'cobros' => $cobros,
            'pagado' => $pagado,
            'saldo' => $saldo,
            'paraPdf' => $paraPdf,
        ];

        if ($paraPdf) {
            return Pdf::loadView($vista, $datos)->setPaper('a4')
                ->download($nombreArchivo)
                ->header('Cache-Control', 'private, no-store');
        }

        return response()->view($vista, [
            ...$datos,
            'nombreArchivo' => $nombreArchivo,
            'urlPdf' => route('filament.negocio.ventas.nota-a4-pdf', ['venta' => $venta]),
        ])->header('Cache-Control', 'private, no-store');
    }
}
