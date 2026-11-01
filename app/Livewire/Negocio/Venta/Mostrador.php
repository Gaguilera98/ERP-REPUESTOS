<?php

namespace App\Livewire\Negocio\Venta;

use App\Compras\Montos;
use App\Enums\EstadoCajaSesion;
use App\Enums\MetodoPago;
use App\Enums\TipoDescuento;
use App\Models\Almacen;
use App\Models\CajaSesion;
use App\Models\Cliente;
use App\Models\InventarioStock;
use App\Models\NegocioConfiguracion;
use App\Models\ProductoVariante;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use App\Models\TenantUser;
use App\Models\Venta;
use App\Ventas\CompletarVentaContado;
use App\Ventas\CompraFlash;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

class Mostrador extends Component
{
    use WithFileUploads;

    public ?int $sucursalId = null;

    public ?int $sucursalIdAnterior = null;

    public ?int $almacenId = null;

    public ?int $clienteId = null;

    public string $busqueda = '';

    /** @var list<array<string, mixed>> */
    public array $lineas = [];

    /** @var list<array<string, mixed>> */
    public array $pagos = [];

    public bool $mostrarFlash = false;

    public ?int $flashVarianteId = null;

    public string $flashCantidad = '1';

    public string $flashCosto = '';

    public bool $flashConfirmo = false;

    public string $flashNombre = '';

    public string $flashSku = '';

    public string $flashPrecioVenta = '';

    public string $ultimoCodigo = '';

    /** @var list<array{sku: string, precio_neto: string, precio_costo: string}> */
    public array $alertasPerdida = [];

    public function mount(): void
    {
        $this->pagos = [[
            'metodo_pago' => MetodoPago::Efectivo->value,
            'monto' => '',
            'numero_referencia_banco' => '',
            'qr_id_transaccion_banco' => '',
            'comprobante' => null,
        ]];

        $usuario = auth('tenant')->user();
        $caja = $usuario instanceof TenantUser
            ? CajaSesion::query()
                ->where('user_id', $usuario->id)
                ->where('estado_sesion', EstadoCajaSesion::Abierta)
                ->orderByDesc('id')
                ->first()
            : null;

        if ($caja instanceof CajaSesion) {
            $this->sucursalId = (int) $caja->sucursal_id;
            $this->actualizarAlmacenPorDefecto();

            return;
        }

        $sucursal = Sucursal::query()->where('is_active', true)->orderBy('nombre')->first();

        if ($sucursal instanceof Sucursal) {
            $this->sucursalId = $sucursal->id;
            $this->actualizarAlmacenPorDefecto();
        }
    }

    public function updatingSucursalId(mixed $value): void
    {
        $this->sucursalIdAnterior = $this->sucursalId;
    }

    public function updatedSucursalId(): void
    {
        if ($this->sucursalId && $this->cajaAbierta() === null) {
            Notification::make()
                ->title('Sin caja en esa sucursal')
                ->body('Abrí la caja de esa sucursal antes de vender ahí.')
                ->warning()
                ->send();

            $this->sucursalId = $this->sucursalIdAnterior;
            $this->actualizarAlmacenPorDefecto();

            return;
        }

        $this->actualizarAlmacenPorDefecto();
    }

    public function buscarYAgregar(): void
    {
        $texto = trim($this->busqueda);

        if ($texto === '') {
            return;
        }

        $variante = ProductoVariante::query()
            ->with('productoMaestro')
            ->where('is_active', true)
            ->where(function ($query) use ($texto): void {
                $query->where('sku', $texto)
                    ->orWhere('codigo_barras', $texto)
                    ->orWhere('sku', 'like', '%'.$texto.'%')
                    ->orWhereHas('productoMaestro', fn ($q) => $q->where('nombre', 'like', '%'.$texto.'%'));
            })
            ->orderBy('sku')
            ->first();

        if (! $variante instanceof ProductoVariante) {
            $this->flashVarianteId = null;
            $this->flashNombre = $texto;
            $this->flashSku = '';
            $this->flashPrecioVenta = '';
            $this->flashCantidad = '1';
            $this->flashCosto = '';
            $this->flashConfirmo = false;
            $this->mostrarFlash = true;

            Notification::make()
                ->title('No encontrado')
                ->body('Podés dar de alta la pieza con entrada express.')
                ->warning()
                ->send();

            return;
        }

        $this->agregarVariante($variante);
        $this->busqueda = '';
    }

    public function agregarVariante(ProductoVariante $variante): void
    {
        foreach ($this->lineas as $i => $linea) {
            if ((int) $linea['producto_variante_id'] === (int) $variante->id) {
                $this->lineas[$i]['cantidad'] = (string) ((float) $linea['cantidad'] + 1);
                $this->recalcularTotalesUi();

                return;
            }
        }

        $disponible = $this->disponibleDe($variante->id);

        if (Montos::comparar($disponible, '0') !== 1) {
            $this->flashVarianteId = $variante->id;
            $this->flashNombre = (string) $variante->productoMaestro?->nombre;
            $this->flashSku = (string) $variante->sku;
            $this->flashPrecioVenta = (string) $variante->precio_venta;
            $this->flashCantidad = '1';
            $this->flashCosto = (string) ($variante->precio_costo ?: '');
            $this->flashConfirmo = false;
            $this->mostrarFlash = true;

            Notification::make()
                ->title('Sin stock')
                ->body('Usá la entrada express para cargar mercadería y vender.')
                ->warning()
                ->send();

            return;
        }

        $this->lineas[] = [
            'producto_variante_id' => $variante->id,
            'sku' => $variante->sku,
            'nombre' => $variante->productoMaestro?->nombre,
            'cantidad' => '1',
            'precio_unitario' => (string) $variante->precio_venta,
            'precio_costo' => (string) $variante->precio_costo,
            'tipo_descuento' => '',
            'valor_descuento' => '',
            'disponible' => $disponible,
        ];

        $this->recalcularTotalesUi();
    }

    public function vaciarCarrito(): void
    {
        $this->lineas = [];
        $this->pagos = [[
            'metodo_pago' => MetodoPago::Efectivo->value,
            'monto' => '',
            'numero_referencia_banco' => '',
            'qr_id_transaccion_banco' => '',
            'comprobante' => null,
        ]];
    }

    public function abrirFlashNuevo(): void
    {
        $this->flashVarianteId = null;
        $this->flashNombre = '';
        $this->flashSku = '';
        $this->flashPrecioVenta = '';
        $this->flashCantidad = '1';
        $this->flashCosto = '';
        $this->flashConfirmo = false;
        $this->mostrarFlash = true;
    }

    public function quitarLinea(int $indice): void
    {
        unset($this->lineas[$indice]);
        $this->lineas = array_values($this->lineas);
        $this->recalcularTotalesUi();
    }

    public function agregarPago(): void
    {
        $this->pagos[] = [
            'metodo_pago' => MetodoPago::Transferencia->value,
            'monto' => '',
            'numero_referencia_banco' => '',
            'qr_id_transaccion_banco' => '',
            'comprobante' => null,
        ];
    }

    public function quitarPago(int $indice): void
    {
        unset($this->pagos[$indice]);
        $this->pagos = array_values($this->pagos);

        if ($this->pagos === []) {
            $this->agregarPago();
        }
    }

    public function ejecutarFlash(CompraFlash $compraFlash): void
    {
        $usuario = $this->usuario();

        try {
            $payload = [
                'almacen_id' => $this->almacenId,
                'cantidad' => $this->flashCantidad,
                'costo_unitario' => $this->flashCosto,
                'confirmo_costos' => $this->flashConfirmo,
            ];

            if ($this->flashVarianteId) {
                $payload['producto_variante_id'] = $this->flashVarianteId;
            } else {
                $payload['producto_nuevo'] = [
                    'nombre' => $this->flashNombre,
                    'sku' => $this->flashSku !== '' ? $this->flashSku : null,
                    'precio_venta' => $this->flashPrecioVenta,
                ];
            }

            $resultado = $compraFlash->ejecutar($payload, $usuario);
            $this->mostrarFlash = false;
            $this->agregarVariante($resultado['variante']);
            $this->busqueda = '';

            Notification::make()
                ->title('Entrada express lista')
                ->success()
                ->send();
        } catch (ValidationException $e) {
            Notification::make()
                ->title('No se pudo cargar')
                ->body(collect($e->errors())->flatten()->first() ?? 'Revisá los datos.')
                ->danger()
                ->send();
        }
    }

    public function completar(CompletarVentaContado $completar): void
    {
        $usuario = $this->usuario();

        if (! $usuario->can('create', Venta::class)) {
            Notification::make()->title('Sin permiso para vender')->danger()->send();

            return;
        }

        try {
            $pagos = [];

            foreach ($this->pagos as $indice => $pago) {
                $path = null;
                $archivo = $pago['comprobante'] ?? null;

                if ($archivo) {
                    $path = $archivo->store('pagos', 'local');
                }

                $pagos[] = [
                    'metodo_pago' => $pago['metodo_pago'],
                    'monto' => $pago['monto'],
                    'numero_referencia_banco' => $pago['numero_referencia_banco'] ?: null,
                    'qr_id_transaccion_banco' => $pago['qr_id_transaccion_banco'] ?: null,
                    'comprobante_archivo_path' => $path,
                ];
            }

            $resultado = $completar->completar([
                'sucursal_id' => $this->sucursalId,
                'almacen_id' => $this->almacenId,
                'cliente_id' => $this->clienteId,
                'lineas' => collect($this->lineas)->map(fn (array $linea): array => [
                    'producto_variante_id' => $linea['producto_variante_id'],
                    'cantidad' => $linea['cantidad'],
                    'tipo_descuento' => $linea['tipo_descuento'] !== '' ? $linea['tipo_descuento'] : null,
                    'valor_descuento' => $linea['valor_descuento'] !== '' ? $linea['valor_descuento'] : null,
                ])->all(),
                'pagos' => $pagos,
            ], $usuario);

            $this->ultimoCodigo = $resultado['venta']->codigo;
            $this->alertasPerdida = $resultado['alertas_perdida'];
            $this->lineas = [];
            $this->pagos = [[
                'metodo_pago' => MetodoPago::Efectivo->value,
                'monto' => '',
                'numero_referencia_banco' => '',
                'qr_id_transaccion_banco' => '',
                'comprobante' => null,
            ]];
            $this->busqueda = '';

            $notificacion = Notification::make()
                ->title('Venta '.$resultado['venta']->codigo)
                ->success();

            if ($resultado['alertas_perdida'] !== []) {
                $notificacion->body('Hay líneas con costo por encima del precio. Revisá la alerta.');
            }

            $notificacion->send();
        } catch (ValidationException $e) {
            Notification::make()
                ->title('No se completó la venta')
                ->body(collect($e->errors())->flatten()->first() ?? 'Revisá los datos.')
                ->danger()
                ->send();
        }
    }

    public function render(): View
    {
        return view('livewire.negocio.venta.mostrador', [
            'sucursales' => Sucursal::query()->where('is_active', true)->orderBy('nombre')->get(),
            'almacenes' => $this->almacenesDeSucursal(),
            'clientes' => Cliente::query()->orderBy('razon_social')->limit(200)->get(),
            'metodos' => MetodoPago::cases(),
            'tiposDescuento' => TipoDescuento::cases(),
            'moneda' => NegocioConfiguracion::codigoMoneda(),
            'cajaAbierta' => $this->cajaAbierta(),
            'totalTentativo' => $this->totalTentativo(),
            'totalRecibido' => $this->totalRecibido(),
            'diferenciaCobros' => $this->diferenciaCobros(),
            'unidadesEnCarrito' => $this->unidadesEnCarrito(),
        ]);
    }

    private function actualizarAlmacenPorDefecto(): void
    {
        if (! $this->sucursalId) {
            $this->almacenId = null;

            return;
        }

        $principal = SucursalAlmacen::query()
            ->where('sucursal_id', $this->sucursalId)
            ->where('es_principal', true)
            ->first();

        $this->almacenId = $principal?->almacen_id
            ?? SucursalAlmacen::query()->where('sucursal_id', $this->sucursalId)->value('almacen_id');
    }

    /**
     * @return Collection<int, Almacen>
     */
    private function almacenesDeSucursal(): Collection
    {
        if (! $this->sucursalId) {
            return collect();
        }

        $ids = SucursalAlmacen::query()
            ->where('sucursal_id', $this->sucursalId)
            ->pluck('almacen_id');

        return Almacen::query()->whereIn('id', $ids)->orderBy('nombre')->get();
    }

    private function cajaAbierta(): ?CajaSesion
    {
        $usuario = auth('tenant')->user();

        if (! $usuario instanceof TenantUser || ! $this->sucursalId) {
            return null;
        }

        return CajaSesion::query()
            ->where('sucursal_id', $this->sucursalId)
            ->where('user_id', $usuario->id)
            ->where('estado_sesion', EstadoCajaSesion::Abierta)
            ->first();
    }

    private function disponibleDe(int $varianteId): string
    {
        if (! $this->almacenId) {
            return '0.0000';
        }

        $stock = InventarioStock::query()
            ->where('producto_variante_id', $varianteId)
            ->where('almacen_id', $this->almacenId)
            ->first();

        if (! $stock) {
            return '0.0000';
        }

        return Montos::sumar(
            (string) $stock->cantidad_actual,
            bcmul((string) $stock->cantidad_reservada, '-1', 4),
            4,
        );
    }

    private function totalTentativo(): string
    {
        $total = '0.00';

        foreach ($this->lineas as $linea) {
            $total = Montos::sumar($total, $this->totalLinea($linea), 2);
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $linea
     */
    public function totalDeLinea(array $linea): string
    {
        try {
            $bruto = Montos::multiplicar((string) ($linea['cantidad'] ?: '0'), (string) ($linea['precio_unitario'] ?: '0'), 2);
            $desc = '0.00';

            if (($linea['tipo_descuento'] ?? '') === TipoDescuento::Porcentaje->value && ($linea['valor_descuento'] ?? '') !== '') {
                $desc = Montos::porcentaje($bruto, (string) $linea['valor_descuento'], 2);
            } elseif (($linea['tipo_descuento'] ?? '') === TipoDescuento::Importe->value && ($linea['valor_descuento'] ?? '') !== '') {
                $desc = Montos::dos((string) $linea['valor_descuento']);
            }

            return Montos::sumar($bruto, bcmul($desc, '-1', 2), 2);
        } catch (\Throwable) {
            return '0.00';
        }
    }

    /**
     * @param  array<string, mixed>  $linea
     */
    private function totalLinea(array $linea): string
    {
        return $this->totalDeLinea($linea);
    }

    private function totalRecibido(): string
    {
        $total = '0.00';

        foreach ($this->pagos as $pago) {
            $monto = trim((string) ($pago['monto'] ?? ''));

            if ($monto === '' || preg_match('/^\d+(\.\d+)?$/', $monto) !== 1) {
                continue;
            }

            $total = Montos::sumar($total, Montos::dos($monto), 2);
        }

        return $total;
    }

    /**
     * @return array{estado: string, monto: string}
     */
    private function diferenciaCobros(): array
    {
        $diff = Montos::sumar($this->totalRecibido(), bcmul($this->totalTentativo(), '-1', 2), 2);
        $cmp = Montos::comparar($diff, '0', 2);

        if ($cmp === 0) {
            return ['estado' => 'completo', 'monto' => '0.00'];
        }

        if ($cmp === -1) {
            return ['estado' => 'falta', 'monto' => Montos::dos(bcmul($diff, '-1', 2))];
        }

        return ['estado' => 'sobra', 'monto' => Montos::dos($diff)];
    }

    private function unidadesEnCarrito(): string
    {
        $total = '0.0000';

        foreach ($this->lineas as $linea) {
            $cant = trim((string) ($linea['cantidad'] ?? '0'));

            if ($cant === '' || preg_match('/^\d+(\.\d+)?$/', $cant) !== 1) {
                continue;
            }

            $total = Montos::sumar($total, Montos::redondear($cant, 4), 4);
        }

        return $total;
    }

    private function recalcularTotalesUi(): void
    {
        // Livewire re-renders; totalTentativo() recalcula.
    }

    private function usuario(): TenantUser
    {
        $usuario = auth('tenant')->user();

        if (! $usuario instanceof TenantUser) {
            throw ValidationException::withMessages([
                'sucursal_id' => 'Tenés que iniciar sesión en el negocio.',
            ]);
        }

        return $usuario;
    }
}
