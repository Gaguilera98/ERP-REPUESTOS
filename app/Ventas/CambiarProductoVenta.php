<?php

namespace App\Ventas;

use App\Enums\CondicionVenta;
use App\Enums\EstadoCajaSesion;
use App\Enums\MetodoPago;
use App\Enums\TipoComprobante;
use App\Models\CajaSesion;
use App\Models\Cliente;
use App\Models\InventarioStock;
use App\Models\NegocioConfiguracion;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CambiarProductoVenta
{
    public function __construct(private RegistrarDevolucion $devoluciones, private CompletarVentaContado $ventas) {}

    public function admite(Venta $venta): bool
    {
        return $venta->estaCompletada() && $venta->condicion === CondicionVenta::Contado
            && $venta->tipo_comprobante === TipoComprobante::NotaVenta && $venta->entregada_en !== null;
    }

    /**
     * @param  list<array{indice: int|string, cantidad: string}>  $seleccion
     * @param  array{producto_variante_id: mixed, cantidad: mixed}  $reemplazo
     * @return array<string, mixed>
     */
    public function revisar(Venta $venta, array $seleccion, array $reemplazo, string $motivoExcepcion, TenantUser $usuario): array
    {
        $venta = Venta::query()->with('cliente')->findOrFail($venta->id);
        $this->autorizar($venta, $usuario);

        if (! $this->admite($venta) || $venta->saldoPendiente() !== '0.00') {
            $this->rechazar('Solo se pueden cambiar productos de ventas de contado entregadas y pagadas.');
        }
        if (! $this->cajaDelCambio($venta, $usuario)->exists()) {
            $this->rechazar('Abrí tu caja de hoy en la sucursal de la venta para registrar el cambio.');
        }

        $devolucion = $this->devoluciones->revisarCambio($venta, $seleccion, $motivoExcepcion, $usuario);
        validator($reemplazo, [
            'producto_variante_id' => ['required', 'integer', 'min:1'],
            'cantidad' => ['required', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
        ])->validate();
        $variante = ProductoVariante::query()->with('productoMaestro.unidadBase')->where('is_active', true)
            ->find($reemplazo['producto_variante_id']);
        if (! $variante) {
            $this->rechazar('Elegí un reemplazo activo del catálogo.');
        }
        if (in_array($variante->id, array_column($devolucion['lineas'], 'producto_variante_id'), true)) {
            $this->rechazar('Elegí un reemplazo distinto de los productos que se devuelven.');
        }

        $linea = $this->ventas->prepararReemplazo($reemplazo, $venta->cliente);
        $stock = InventarioStock::query()->where('almacen_id', $venta->almacen_id)->where('producto_variante_id', $variante->id)->first();
        if (! $stock || bccomp($linea['cantidad_base'], bcsub($stock->cantidad_actual, $stock->cantidad_reservada, 4), 4) > 0) {
            $this->rechazar('No hay stock disponible de '.$variante->sku.' en el almacén de la venta.');
        }

        $diferencia = bcsub($linea['total_linea'], $devolucion['total'], 2);
        $politica = $venta->politica_devolucion_snapshot;
        $resoluciones = [];
        if (bccomp($diferencia, '0', 2) < 0) {
            if ($politica['permite_reembolso'] ?? false) {
                $resoluciones['reembolso'] = 'Reembolsar por los métodos originales';
            }
            if ($politica['permite_saldo_favor'] ?? false) {
                $resoluciones['saldo_favor'] = 'Guardar saldo a favor';
            }
            if ($resoluciones === []) {
                $this->rechazar('Esta venta solo admite cambios. Elegí un reemplazo de igual o mayor valor.');
            }
        }

        $compensaciones = [];
        $remanente = [];
        $pendiente = bccomp($diferencia, '0', 2) < 0 ? $linea['total_linea'] : $devolucion['total'];
        foreach ($devolucion['pagos'] as $parte) {
            $aplicado = bccomp($pendiente, $parte['monto'], 2) > 0 ? $parte['monto'] : $pendiente;
            if ($aplicado !== '0.00') {
                $compensaciones[] = ['origen' => $parte['origen'], 'monto' => $aplicado];
                $pendiente = bcsub($pendiente, $aplicado, 2);
            }
            $sobrante = bcsub($parte['monto'], $aplicado, 2);
            if ($sobrante !== '0.00') {
                $remanente[] = [...$parte, 'monto' => $sobrante];
            }
        }

        return [
            'devolucion' => $devolucion, 'reemplazo' => $linea, 'diferencia' => $diferencia,
            'producto' => $variante->sku.' · '.$variante->productoMaestro->nombre,
            'unidad' => $variante->productoMaestro->unidadBase?->simbolo,
            'resoluciones' => $resoluciones, 'compensaciones' => $compensaciones, 'remanente' => $remanente,
            'revision' => hash('sha256', serialize([$devolucion['revision'], $linea, $compensaciones, $remanente, $resoluciones])),
        ];
    }

    /** @param array<string, mixed> $datos */
    public function confirmar(Venta $venta, array $datos, TenantUser $usuario): Venta
    {
        $this->autorizar($venta, $usuario);
        validator($datos, [
            'motivo' => ['required', 'string', 'max:500'],
            'motivo_excepcion' => ['nullable', 'string', 'max:500'],
            'lineas' => ['required', 'array'],
            'reemplazo' => ['required', 'array'],
            'revision' => ['required', 'string', 'size:64'],
            'cliente_saldo_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        $motivo = trim($datos['motivo']);
        $excepcion = trim($datos['motivo_excepcion'] ?? '');
        if ($motivo === '') {
            $this->rechazar('Escribí el motivo del cambio.');
        }

        app(GestionarReservas::class)->procesarVencidas();

        return DB::transaction(function () use ($venta, $datos, $usuario, $motivo, $excepcion): Venta {
            $caja = $this->cajaDelCambio($venta, $usuario)->lockForUpdate()->first();
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);
            if (! $caja) {
                $this->rechazar('Abrí tu caja de hoy en la sucursal de la venta para registrar el cambio.');
            }
            $clienteId = $venta->cliente_id ?? ($datos['cliente_saldo_id'] ?? null);
            $cliente = $clienteId === null ? null : Cliente::query()->lockForUpdate()->find($clienteId);

            NegocioConfiguracion::query()->lockForUpdate()->firstOrFail();
            $resumen = $this->revisar($venta, $datos['lineas'], $datos['reemplazo'], $excepcion, $usuario);
            $variantes = [...array_column($resumen['devolucion']['lineas'], 'producto_variante_id'), $resumen['reemplazo']['producto_variante_id']];
            ProductoVariante::query()->whereKey($variantes)->orderBy('id')->lockForUpdate()->get(['id']);
            $resumen = $this->revisar($venta, $datos['lineas'], $datos['reemplazo'], $excepcion, $usuario);
            if (! hash_equals($resumen['revision'], $datos['revision'])) {
                $this->rechazar('La venta o el reemplazo cambiaron. Volvé a revisar el cambio.');
            }

            $resolucion = null;
            $pagos = [];
            if (bccomp($resumen['diferencia'], '0', 2) < 0) {
                $resolucion = $datos['resolucion'] ?? null;
                if (! is_string($resolucion) || ! isset($resumen['resoluciones'][$resolucion])) {
                    $this->rechazar('Elegí cómo resolver la diferencia a favor del cliente.');
                }
                if ($resolucion === 'saldo_favor' && ! $cliente) {
                    $this->rechazar('Identificá al cliente para guardar la diferencia como saldo a favor.');
                }
            } elseif (bccomp($resumen['diferencia'], '0', 2) > 0) {
                $pagos[] = $this->pagoDiferencia($datos, $resumen['diferencia']);
            }

            $nota = $this->devoluciones->guardar($venta, [
                ...$resumen['devolucion'], 'resolucion' => $resolucion,
                'resolver' => bccomp($resumen['diferencia'], '0', 2) < 0 ? bcsub('0', $resumen['diferencia'], 2) : '0.00',
                'pagos' => $resumen['remanente'], 'revision' => $resumen['revision'],
            ], $motivo, $usuario, $caja, $resolucion === 'saldo_favor' ? $cliente->id : null);

            $reemplazo = $this->ventas->completarReemplazo([
                'sucursal_id' => $venta->sucursal_id, 'almacen_id' => $venta->almacen_id,
                'cliente_id' => $venta->cliente_id, 'lineas' => [[
                    'producto_variante_id' => $resumen['reemplazo']['producto_variante_id'],
                    'cantidad' => $resumen['reemplazo']['cantidad'],
                ]], 'pagos' => $pagos,
            ], $usuario, $nota, $resumen['compensaciones'], $resumen['reemplazo']['total_linea']);
            $nota->update(['venta_reemplazo_id' => $reemplazo->id]);

            return $reemplazo;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    private function pagoDiferencia(array $datos, string $monto): array
    {
        $metodo = is_string($datos['metodo_pago'] ?? null) ? MetodoPago::tryFrom($datos['metodo_pago']) : null;
        if ($metodo === null) {
            $this->rechazar('Elegí el método para cobrar la diferencia.');
        }

        return [
            'metodo_pago' => $metodo->value, 'monto' => $monto,
            'efectivo_recibido' => $metodo === MetodoPago::Efectivo ? ($datos['efectivo_recibido'] ?? null) : null,
            'numero_referencia_banco' => $datos['numero_referencia_banco'] ?? null,
            'comprobante_archivo_path' => $metodo->aceptaComprobanteArchivo() ? ($datos['comprobante'] ?? null) : null,
        ];
    }

    /** @return Builder<CajaSesion> */
    private function cajaDelCambio(Venta $venta, TenantUser $usuario): Builder
    {
        return CajaSesion::query()->where('sucursal_id', $venta->sucursal_id)->where('user_id', $usuario->id)
            ->where('estado_sesion', EstadoCajaSesion::Abierta)->whereDate('fecha_apertura', now()->toDateString());
    }

    private function autorizar(Venta $venta, TenantUser $usuario): void
    {
        abort_unless($usuario->can('view', $venta) && $usuario->can('create', Venta::class) && $usuario->can('Registrar:Devolucion'), 403);
    }

    private function rechazar(string $mensaje): never
    {
        throw ValidationException::withMessages(['cambio' => $mensaje]);
    }
}
