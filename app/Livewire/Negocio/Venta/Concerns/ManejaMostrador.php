<?php

namespace App\Livewire\Negocio\Venta\Concerns;

use App\Clientes\BuscarClientesMostrador;
use App\Clientes\RegistrarClienteRapido;
use App\Compras\Montos;
use App\Enums\EstadoCajaSesion;
use App\Enums\MetodoPago;
use App\Enums\TipoCliente;
use App\Enums\TipoDescuento;
use App\Enums\TipoPrecioCliente;
use App\Models\Almacen;
use App\Models\CajaSesion;
use App\Models\Categoria;
use App\Models\CategoriaAtributo;
use App\Models\Cliente;
use App\Models\InventarioStock;
use App\Models\NegocioConfiguracion;
use App\Models\ProductoVariante;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use App\Models\TenantUser;
use App\Models\Venta;
use App\Ventas\BuscarProductosMostrador;
use App\Ventas\CompletarVentaContado;
use App\Ventas\CompraFlash;
use App\Ventas\GestionarReservas;
use App\Ventas\PrecioDeLista;
use App\Ventas\SaldoAFavorCliente;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

trait ManejaMostrador
{
    public ?int $sucursalId = null;

    public ?int $sucursalIdAnterior = null;

    public ?int $almacenId = null;

    public ?int $clienteId = null;

    public string $listaPrecio = 'menor';

    public bool $mostrarEleccionPrecio = false;

    public ?int $clientePendienteId = null;

    public string $clientePendienteNombre = '';

    /** @var list<array{sku: string, nombre: string, menor: string, mayor: string}> */
    public array $comparacionPrecio = [];

    public string $totalMenorPendiente = '0.00';

    public string $totalMayorPendiente = '0.00';

    public bool $eleccionAntesDeDescuento = false;

    public bool $mostrarCliente = false;

    public bool $mostrandoRegistroCliente = false;

    public string $busquedaCliente = '';

    /** @var list<array{id: int, nombre: string, documento: string, telefono: string}> */
    public array $resultadosCliente = [];

    public string $clienteTipo = 'persona_natural';

    public string $clienteNombres = '';

    public string $clienteApellidos = '';

    public string $clienteRazonSocial = '';

    public string $clienteDocumento = '';

    public string $clienteTelefono = '';

    public string $clienteEmail = '';

    public string $busqueda = '';

    /** @var list<array{id: int, sku: string, nombre: string, precio: string, disponible: string, unidad_simbolo: string, sin_stock: bool, ficha: string}> */
    public array $resultadosBusqueda = [];

    public int $totalSugerencias = 0;

    public int $indiceResultado = 0;

    public bool $mostrarBusquedaAvanzada = false;

    public string $busquedaAvanzada = '';

    public ?int $filtroCategoriaId = null;

    public string $filtroDisponibilidad = 'todos';

    /** @var array<string, string> */
    public array $filtrosAtributos = [];

    /** @var list<string> */
    public array $atributosVisibles = [];

    public string $atributoParaAgregar = '';

    public int $paginaAvanzada = 1;

    /** @var list<array{id: int, sku: string, nombre: string, precio: string, disponible: string, unidad_simbolo: string, sin_stock: bool, ficha: string}> */
    public array $resultadosAvanzados = [];

    public int $totalAvanzados = 0;

    public int $ultimasPaginasAvanzadas = 1;

    /** @var list<array<string, mixed>> */
    public array $lineas = [];

    /** @var list<array<string, mixed>> */
    public array $pagos = [];

    public bool $ventaFiada = false;

    public bool $ventaReserva = false;

    public string $saldoFavor = '';

    public string $venceReserva = '';

    public function seleccionarOperacion(string $operacion): void
    {
        $this->ventaFiada = $operacion === 'credito';
        $this->ventaReserva = $operacion === 'reserva';
        if ($this->ventaReserva && $this->venceReserva === '') {
            $this->venceReserva = now()->addHours(NegocioConfiguracion::query()->first()?->horas_reserva ?? 48)->endOfDay()->format('Y-m-d\TH:i');
        }
    }

    public bool $mostrarDescuentoVenta = false;

    public string $tipoDescuentoVenta = '';

    public string $valorDescuentoVenta = '';

    public bool $mostrarFlash = false;

    public ?int $flashVarianteId = null;

    public string $flashCantidad = '1';

    public string $flashCosto = '';

    public bool $flashConfirmo = false;

    public string $flashNombre = '';

    public string $flashSku = '';

    public string $flashPrecioVenta = '';

    public string $ultimoCodigo = '';

    public string $ultimoTotal = '';

    /** @var list<array{sku: string, precio_neto: string, precio_costo: string}> */
    public array $alertasPerdida = [];

    public function inicializarMostrador(): void
    {
        app(GestionarReservas::class)->procesarVencidas();

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
                ->whereDate('fecha_apertura', now()->toDateString())
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
        $this->refrescarResultadosBusqueda(trim($this->busqueda));
    }

    public function updatedAlmacenId(): void
    {
        $this->refrescarResultadosBusqueda(trim($this->busqueda));

        if ($this->mostrarBusquedaAvanzada) {
            $this->paginaAvanzada = 1;
            $this->refrescarBusquedaAvanzada();
        }
    }

    public function updatedBusqueda(string $value): void
    {
        $this->refrescarResultadosBusqueda(trim($value));
    }

    public function buscarYAgregar(): void
    {
        if ($this->mostrarEleccionPrecio) {
            return;
        }

        $texto = trim($this->busqueda);

        if ($texto === '') {
            return;
        }

        $exacta = $this->buscador()->exacta($texto);

        if ($exacta instanceof ProductoVariante) {
            $this->agregarVariante($exacta);
            $this->limpiarBusqueda();

            return;
        }

        if ($this->resultadosBusqueda === []) {
            $this->refrescarResultadosBusqueda($texto);
        }

        if (count($this->resultadosBusqueda) === 1) {
            $this->seleccionarResultado((int) $this->resultadosBusqueda[0]['id']);

            return;
        }

        if (count($this->resultadosBusqueda) > 1) {
            $indice = max(0, min($this->indiceResultado, count($this->resultadosBusqueda) - 1));
            $this->seleccionarResultado((int) $this->resultadosBusqueda[$indice]['id']);

            return;
        }

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
    }

    public function seleccionarResultado(int $varianteId): void
    {
        $variante = ProductoVariante::query()
            ->with(['productoMaestro.unidadBase'])
            ->whereKey($varianteId)
            ->where('is_active', true)
            ->first();

        if (! $variante instanceof ProductoVariante) {
            Notification::make()
                ->title('Producto no disponible')
                ->warning()
                ->send();

            return;
        }

        $this->agregarVariante($variante);
        $this->limpiarBusqueda();
        $this->mostrarBusquedaAvanzada = false;
    }

    public function limpiarBusqueda(): void
    {
        $this->busqueda = '';
        $this->resultadosBusqueda = [];
        $this->totalSugerencias = 0;
        $this->indiceResultado = 0;
    }

    public function abrirBusquedaAvanzada(): void
    {
        $this->busquedaAvanzada = trim($this->busqueda);
        $this->paginaAvanzada = 1;
        $this->mostrarBusquedaAvanzada = true;
        $this->refrescarBusquedaAvanzada();
    }

    public function cerrarBusquedaAvanzada(): void
    {
        $this->mostrarBusquedaAvanzada = false;
    }

    public function updatedBusquedaAvanzada(): void
    {
        $this->paginaAvanzada = 1;
        $this->refrescarBusquedaAvanzada();
    }

    public function updatingFiltroCategoriaId(mixed &$value): void
    {
        $value = $value === '' || $value === null ? null : (int) $value;
    }

    public function updatedFiltroCategoriaId(): void
    {
        $this->filtrosAtributos = [];
        $this->atributosVisibles = [];
        $this->atributoParaAgregar = '';
        $this->paginaAvanzada = 1;
        $this->refrescarBusquedaAvanzada();
    }

    public function updatedAtributoParaAgregar(string $value): void
    {
        if ($value === '') {
            return;
        }

        $this->agregarFiltroAtributo($value);
    }

    public function agregarFiltroAtributo(string $clave = ''): void
    {
        $clave = $clave !== '' ? $clave : $this->atributoParaAgregar;
        $this->atributoParaAgregar = '';

        if ($clave === '' || in_array($clave, $this->atributosVisibles, true)) {
            return;
        }

        if (! $this->atributosDeCategoria()->contains(fn (CategoriaAtributo $atributo): bool => $atributo->clave === $clave)) {
            return;
        }

        $this->atributosVisibles[] = $clave;
        $this->filtrosAtributos[$clave] = $this->filtrosAtributos[$clave] ?? '';
    }

    public function quitarFiltroAtributo(string $clave): void
    {
        $this->atributosVisibles = array_values(array_filter(
            $this->atributosVisibles,
            fn (string $visible): bool => $visible !== $clave,
        ));
        unset($this->filtrosAtributos[$clave]);
        $this->paginaAvanzada = 1;
        $this->refrescarBusquedaAvanzada();
    }

    public function updatedFiltroDisponibilidad(): void
    {
        $this->paginaAvanzada = 1;
        $this->refrescarBusquedaAvanzada();
    }

    public function updatedFiltrosAtributos(): void
    {
        $this->paginaAvanzada = 1;
        $this->refrescarBusquedaAvanzada();
    }

    public function paginaAvanzadaAnterior(): void
    {
        $this->paginaAvanzada = max(1, $this->paginaAvanzada - 1);
        $this->refrescarBusquedaAvanzada();
    }

    public function paginaAvanzadaSiguiente(): void
    {
        $this->paginaAvanzada = min($this->ultimasPaginasAvanzadas, $this->paginaAvanzada + 1);
        $this->refrescarBusquedaAvanzada();
    }

    public function expressDesdeBusqueda(?int $varianteId = null): void
    {
        if ($varianteId) {
            $variante = ProductoVariante::query()->with(['productoMaestro.unidadBase'])->whereKey($varianteId)->first();

            if ($variante instanceof ProductoVariante) {
                $this->agregarVariante($variante);
                $this->mostrarBusquedaAvanzada = false;

                return;
            }
        }

        $nombre = $this->mostrarBusquedaAvanzada && trim($this->busquedaAvanzada) !== ''
            ? trim($this->busquedaAvanzada)
            : trim($this->busqueda);
        $this->abrirFlashNuevo();
        $this->flashNombre = $nombre;
        $this->mostrarBusquedaAvanzada = false;
    }

    public function bajarResultado(): void
    {
        if ($this->resultadosBusqueda === []) {
            return;
        }

        $this->indiceResultado = min($this->indiceResultado + 1, count($this->resultadosBusqueda) - 1);
    }

    public function subirResultado(): void
    {
        if ($this->resultadosBusqueda === []) {
            return;
        }

        $this->indiceResultado = max($this->indiceResultado - 1, 0);
    }

    public function agregarVariante(ProductoVariante $variante): void
    {
        foreach ($this->lineas as $i => $linea) {
            if ((int) $linea['producto_variante_id'] === (int) $variante->id) {
                $this->lineas[$i]['cantidad'] = Montos::sinCeros(Montos::sumar((string) $linea['cantidad'], '1', 4));
                $this->recalcularTotalesUi();

                return;
            }
        }

        $disponible = $this->disponibleDe($variante->id);

        if (Montos::comparar($disponible, '0') !== 1) {
            $this->flashVarianteId = $variante->id;
            $this->flashNombre = (string) $variante->productoMaestro?->nombre;
            $this->flashSku = (string) $variante->sku;
            $this->flashPrecioVenta = Montos::sinCeros((string) $variante->precio_venta, 2);
            $this->flashCantidad = '1';
            $this->flashCosto = filled($variante->precio_costo) && Montos::comparar((string) $variante->precio_costo, '0') === 1
                ? Montos::sinCeros((string) $variante->precio_costo, 2)
                : '';
            $this->flashConfirmo = false;
            $this->mostrarFlash = true;

            Notification::make()
                ->title('Sin stock')
                ->body('Usá la entrada express para cargar mercadería y vender.')
                ->warning()
                ->send();

            return;
        }

        $variante->loadMissing('productoMaestro.unidadBase');

        $precios = $this->preciosDe($variante);

        $this->lineas[] = [
            'producto_variante_id' => $variante->id,
            'sku' => $variante->sku,
            'nombre' => $variante->productoMaestro?->nombre,
            'cantidad' => '1',
            'precio_unitario' => $precios['precio_unitario'],
            'nota_precio' => $precios['nota_precio'],
            'precio_costo' => (string) $variante->precio_costo,
            'tipo_descuento' => '',
            'valor_descuento' => '',
            'disponible' => $disponible,
            'unidad_simbolo' => (string) ($variante->productoMaestro?->unidadBase?->simbolo ?: 'pza'),
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
        $this->quitarDescuentoVenta();
    }

    public function mostrarCampoDescuentoVenta(): void
    {
        $this->mostrarDescuentoVenta = true;

        if ($this->tipoDescuentoVenta === '') {
            $this->tipoDescuentoVenta = TipoDescuento::Porcentaje->value;
        }
    }

    public function quitarDescuentoVenta(): void
    {
        $this->mostrarDescuentoVenta = false;
        $this->tipoDescuentoVenta = '';
        $this->valorDescuentoVenta = '';
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

    public function cambiarCantidad(int $indice, int $delta): void
    {
        if (! isset($this->lineas[$indice])) {
            return;
        }

        $actual = trim((string) ($this->lineas[$indice]['cantidad'] ?? '0'));

        if ($actual === '' || preg_match('/^\d+(\.\d+)?$/', $actual) !== 1) {
            $actual = '0';
        }

        $nueva = Montos::sumar($actual, (string) $delta, 4);

        if (Montos::comparar($nueva, '0', 4) !== 1) {
            $this->quitarLinea($indice);

            return;
        }

        $this->lineas[$indice]['cantidad'] = Montos::sinCeros($nueva);
        $this->recalcularTotalesUi();
    }

    public function compactarCantidadVisible(int $indice): void
    {
        if (! isset($this->lineas[$indice])) {
            return;
        }

        $actual = trim((string) ($this->lineas[$indice]['cantidad'] ?? ''));

        if ($actual === '' || preg_match('/^\d+(\.\d+)?$/', $actual) !== 1) {
            return;
        }

        $this->lineas[$indice]['cantidad'] = Montos::sinCeros($actual);
    }

    public function cerrarFlash(): void
    {
        $this->mostrarFlash = false;
        $this->flashConfirmo = false;
        $this->flashVarianteId = null;
    }

    public function limpiarUltimaVenta(): void
    {
        $this->ultimoCodigo = '';
        $this->ultimoTotal = '';
        $this->alertasPerdida = [];
    }

    public function updatedPagos(mixed $value, string $key): void
    {
        if (! str_ends_with($key, 'metodo_pago')) {
            return;
        }

        $indice = (int) str($key)->before('.')->toString();

        if (! isset($this->pagos[$indice]) || ! is_array($this->pagos[$indice])) {
            return;
        }

        $metodo = is_string($value) ? $value : '';
        $this->limpiarCamposDePago($indice, $metodo);
        $this->resetValidation();
    }

    public function agregarPago(): void
    {
        $this->pagos[] = [
            'metodo_pago' => MetodoPago::Efectivo->value,
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
        $this->resetValidation();

        if ($this->pagos === []) {
            $this->agregarPago();
        }
    }

    public function ejecutarFlash(CompraFlash $compraFlash): void
    {
        if (! $this->mostrarFlash) {
            return;
        }

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
            $this->flashConfirmo = false;
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

        $this->resetValidation();

        if ($this->cajaAbierta() === null) {
            Notification::make()->title('Abrí la caja de hoy')->body('Necesitás una caja abierta en esta sucursal para confirmar la operación.')->warning()->send();

            return;
        }

        try {
            $this->validarComprobantesDePago();

            $pagos = [];

            foreach ($this->pagos as $indice => $pago) {
                if (($this->ventaFiada || $this->saldoFavorTentativo() !== '0.00') && trim((string) $pago['monto']) === '' && empty($pago['comprobante'])) {
                    continue;
                }
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

            $datosVenta = [
                'sucursal_id' => $this->sucursalId,
                'almacen_id' => $this->almacenId,
                'cliente_id' => $this->clienteId,
                'tipo_precio' => $this->listaActiva()->value,
                'tipo_descuento' => $this->tipoDescuentoVenta !== '' ? $this->tipoDescuentoVenta : null,
                'valor_descuento' => $this->valorDescuentoVenta !== '' ? $this->valorDescuentoVenta : null,
                'lineas' => collect($this->lineas)->map(fn (array $linea): array => [
                    'producto_variante_id' => $linea['producto_variante_id'],
                    'cantidad' => $linea['cantidad'],
                    'tipo_descuento' => $linea['tipo_descuento'] !== '' ? $linea['tipo_descuento'] : null,
                    'valor_descuento' => $linea['valor_descuento'] !== '' ? $linea['valor_descuento'] : null,
                ])->all(),
                'pagos' => $pagos,
                'vence_en' => $this->venceReserva,
                'saldo_favor' => $this->saldoFavor === '' ? '0' : $this->saldoFavor,
            ];
            $resultado = match (true) {
                $this->ventaReserva => $completar->reservar($datosVenta, $usuario),
                $this->ventaFiada => $completar->venderFiado($datosVenta, $usuario),
                default => $completar->completar($datosVenta, $usuario),
            };

            $this->ultimoCodigo = $resultado['venta']->codigo;
            $this->ultimoTotal = Montos::dos((string) $resultado['venta']->total);
            $this->alertasPerdida = $resultado['alertas_perdida'];
            $this->lineas = [];
            $this->ventaFiada = false;
            $this->ventaReserva = false;
            $this->venceReserva = '';
            $this->saldoFavor = '';
            $this->pagos = [[
                'metodo_pago' => MetodoPago::Efectivo->value,
                'monto' => '',
                'numero_referencia_banco' => '',
                'qr_id_transaccion_banco' => '',
                'comprobante' => null,
            ]];
            $this->quitarDescuentoVenta();
            $this->busqueda = '';

            Notification::make()
                ->title('Venta '.$resultado['venta']->codigo)
                ->success()
                ->send();

            if ($resultado['alertas_perdida'] !== []) {
                $detalle = collect($resultado['alertas_perdida'])
                    ->map(fn (array $alerta): string => $alerta['sku']
                        .' · costo '.Montos::sinCeros((string) $alerta['precio_costo'])
                        .' > precio '.Montos::sinCeros((string) $alerta['precio_neto']))
                    ->implode("\n");

                Notification::make()
                    ->title('Costo por encima del precio')
                    ->body($detalle."\nLa venta igual se guardó. Cerrá este aviso cuando lo leas.")
                    ->warning()
                    ->persistent()
                    ->send();
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                foreach ($mensajes as $mensaje) {
                    $this->addError($campo, $mensaje);
                }
            }

            Notification::make()
                ->title('No se completó la venta')
                ->body(collect($e->errors())->flatten()->first() ?? 'Revisá los datos.')
                ->danger()
                ->send();
        }
    }

    private function aplicarListaPrecio(): void
    {
        foreach ($this->lineas as $indice => $linea) {
            $variante = ProductoVariante::query()->find($linea['producto_variante_id'] ?? null);

            if (! $variante instanceof ProductoVariante) {
                continue;
            }

            $this->lineas[$indice] = array_merge($linea, $this->preciosDe($variante));
        }

        $this->recalcularTotalesUi();

        if (trim($this->busqueda) !== '') {
            $this->refrescarResultadosBusqueda($this->busqueda);
        }

        if ($this->mostrarBusquedaAvanzada && $this->tieneCriterioBusquedaAvanzada()) {
            $this->refrescarBusquedaAvanzada();
        }
    }

    /**
     * @return array{precio_unitario: string, nota_precio: string}
     */
    private function preciosDe(ProductoVariante $variante, ?TipoPrecioCliente $lista = null): array
    {
        $lista ??= $this->listaActiva();
        $precios = app(PrecioDeLista::class);

        return [
            'precio_unitario' => $precios->de($variante, $lista),
            'nota_precio' => $precios->nota($variante, $lista),
        ];
    }

    private function listaActiva(): TipoPrecioCliente
    {
        return TipoPrecioCliente::tryFrom($this->listaPrecio) ?? TipoPrecioCliente::Menor;
    }

    private function tomarCliente(Cliente $cliente): void
    {
        $this->saldoFavor = '';
        if ($cliente->tipo_precio !== TipoPrecioCliente::Mayor || $this->clienteId === $cliente->id) {
            $this->clienteId = $cliente->id;
            $this->cerrarEleccionPrecio();

            return;
        }

        $this->clientePendienteId = $cliente->id;
        $this->clientePendienteNombre = (string) $cliente->nombre_mostrar;
        $this->comparacionPrecio = $this->compararPrecios();
        $this->eleccionAntesDeDescuento = $this->hayDescuentoEnVenta();
        $this->mostrarEleccionPrecio = true;
    }

    private function cerrarEleccionPrecio(): void
    {
        $this->mostrarEleccionPrecio = false;
        $this->clientePendienteId = null;
        $this->clientePendienteNombre = '';
        $this->comparacionPrecio = [];
        $this->totalMenorPendiente = '0.00';
        $this->totalMayorPendiente = '0.00';
        $this->eleccionAntesDeDescuento = false;
    }

    /**
     * @return list<array{sku: string, nombre: string, menor: string, mayor: string}>
     */
    private function compararPrecios(): array
    {
        $ids = collect($this->lineas)->pluck('producto_variante_id')->filter()->all();
        $variantes = ProductoVariante::query()->whereIn('id', $ids)->get()->keyBy('id');
        $precios = app(PrecioDeLista::class);
        $filas = [];
        $totalMenor = '0.00';
        $totalMayor = '0.00';

        foreach ($this->lineas as $linea) {
            $variante = $variantes->get($linea['producto_variante_id'] ?? null);

            if (! $variante instanceof ProductoVariante) {
                continue;
            }

            $menor = $precios->de($variante, TipoPrecioCliente::Menor);
            $mayor = $precios->de($variante, TipoPrecioCliente::Mayor);
            $cantidad = (string) ($linea['cantidad'] ?: '0');
            $totalMenor = Montos::sumar($totalMenor, Montos::multiplicar($cantidad, $menor, 2), 2);
            $totalMayor = Montos::sumar($totalMayor, Montos::multiplicar($cantidad, $mayor, 2), 2);
            $filas[] = [
                'sku' => (string) ($linea['sku'] ?? $variante->sku),
                'nombre' => (string) ($linea['nombre'] ?? ''),
                'menor' => $menor,
                'mayor' => $mayor,
            ];
        }

        $this->totalMenorPendiente = $totalMenor;
        $this->totalMayorPendiente = $totalMayor;

        return $filas;
    }

    private function hayDescuentoEnVenta(): bool
    {
        if ($this->mostrarDescuentoVenta && trim($this->valorDescuentoVenta) !== '') {
            return true;
        }

        foreach ($this->lineas as $linea) {
            if (($linea['tipo_descuento'] ?? '') !== '' && trim((string) ($linea['valor_descuento'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function limpiarCamposDePago(int $indice, string $metodo): void
    {
        if (! in_array($metodo, ['transferencia', 'qr_externo', 'voucher_tarjeta'], true)) {
            $this->pagos[$indice]['numero_referencia_banco'] = '';
        }

        if ($metodo !== 'qr_externo') {
            $this->pagos[$indice]['qr_id_transaccion_banco'] = '';
        }

        $metodoEnum = MetodoPago::tryFrom($metodo);

        if ($metodoEnum === null || ! $metodoEnum->aceptaComprobanteArchivo()) {
            $this->pagos[$indice]['comprobante'] = null;
        }
    }

    private function validarComprobantesDePago(): void
    {
        $config = NegocioConfiguracion::query()->first();
        $mimes = implode(',', NegocioConfiguracion::COMPROBANTE_EXTENSIONES);
        $reglas = [];
        $mensajes = [];

        foreach ($this->pagos as $indice => $pago) {
            if ($this->saldoFavorTentativo() !== '0.00' && trim((string) ($pago['monto'] ?? '')) === '' && empty($pago['comprobante'])) {
                continue;
            }
            $metodo = MetodoPago::tryFrom((string) ($pago['metodo_pago'] ?? ''));

            if ($metodo === null || ! $metodo->aceptaComprobanteArchivo()) {
                continue;
            }

            $campo = "pagos.{$indice}.comprobante";
            $obligatorio = $config?->exigeComprobanteArchivo($metodo) ?? false;
            $reglas[$campo] = [
                $obligatorio ? 'required' : 'nullable',
                'file',
                'mimes:'.$mimes,
                'max:'.NegocioConfiguracion::COMPROBANTE_MAX_KB,
            ];
            $mensajes["{$campo}.required"] = 'Adjuntá el comprobante (PDF o imagen).';
            $mensajes["{$campo}.mimes"] = 'El comprobante tiene que ser PDF o imagen.';
            $mensajes["{$campo}.max"] = 'El comprobante no puede pasar de 5 MB.';
        }

        if ($reglas !== []) {
            $this->validate($reglas, $mensajes);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function datosVistaMostrador(): array
    {
        return [
            'sucursales' => Sucursal::query()->where('is_active', true)->orderBy('nombre')->get(),
            'almacenes' => $this->almacenesDeSucursal(),
            'clienteSeleccionado' => $this->clienteSeleccionado(),
            'puedeRegistrarCliente' => $this->usuario()->can('create', Cliente::class),
            'metodos' => MetodoPago::cases(),
            'tiposDescuento' => TipoDescuento::cases(),
            'moneda' => NegocioConfiguracion::codigoMoneda(),
            'comprobantesObligatorios' => NegocioConfiguracion::query()->first()?->comprobantesObligatorios() ?? [],
            'cajaAbierta' => $this->cajaAbierta(),
            'subtotalBruto' => $this->subtotalBrutoTentativo(),
            'descuentoTotal' => $this->descuentoTotalTentativo(),
            'totalesLinea' => $this->totalesPorLinea(),
            'totalTentativo' => $this->totalTentativo(),
            'totalRecibido' => $this->totalRecibido(),
            'saldoFavorDisponible' => $this->clienteId ? app(SaldoAFavorCliente::class)->disponible($this->clienteId) : '0.00',
            'saldoFavorAplicado' => $this->saldoFavorTentativo(),
            'permiteReembolsoSenas' => NegocioConfiguracion::query()->first()?->permite_reembolso_senas ?? true,
            'diferenciaCobros' => $this->diferenciaCobros(),
            'categorias' => Categoria::query()->where('is_active', true)->orderBy('nombre')->get(),
            'atributosCategoria' => $this->atributosDeCategoria(),
            'atributosVisibles' => $this->atributosVisibles,
            'busquedaAvanzadaLista' => $this->tieneCriterioBusquedaAvanzada(),
        ];
    }

    protected function refrescarResultadosBusqueda(string $texto): void
    {
        $this->indiceResultado = 0;

        $resultado = $this->buscador()->sugerir([
            'texto' => $texto,
            'almacen_id' => $this->almacenId,
            'lista' => $this->listaActiva(),
        ]);

        $this->resultadosBusqueda = $resultado['items'];
        $this->totalSugerencias = $resultado['total'];
    }

    protected function tieneCriterioBusquedaAvanzada(): bool
    {
        if (trim($this->busquedaAvanzada) !== '') {
            return true;
        }

        if ($this->filtroCategoriaId) {
            return true;
        }

        if ($this->filtroDisponibilidad !== 'todos') {
            return true;
        }

        foreach ($this->filtrosAtributosActivos() as $valor) {
            if (trim((string) $valor) !== '') {
                return true;
            }
        }

        return false;
    }

    protected function refrescarBusquedaAvanzada(): void
    {
        if (! $this->tieneCriterioBusquedaAvanzada()) {
            $this->resultadosAvanzados = [];
            $this->totalAvanzados = 0;
            $this->ultimasPaginasAvanzadas = 1;
            $this->paginaAvanzada = 1;

            return;
        }

        $pagina = $this->buscador()->paginar([
            'texto' => $this->busquedaAvanzada,
            'almacen_id' => $this->almacenId,
            'categoria_id' => $this->filtroCategoriaId,
            'disponibilidad' => $this->filtroDisponibilidad,
            'atributos' => $this->filtrosAtributosActivos(),
            'pagina' => $this->paginaAvanzada,
            'lista' => $this->listaActiva(),
        ]);

        $this->resultadosAvanzados = $pagina->items();
        $this->totalAvanzados = $pagina->total();
        $this->ultimasPaginasAvanzadas = max(1, $pagina->lastPage());
        $this->paginaAvanzada = $pagina->currentPage();
    }

    /**
     * @return Collection<int, CategoriaAtributo>
     */
    protected function atributosDeCategoria(): Collection
    {
        if (! $this->filtroCategoriaId) {
            return collect();
        }

        return CategoriaAtributo::query()
            ->with('unidadMedida')
            ->where('categoria_id', $this->filtroCategoriaId)
            ->orderBy('orden')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    protected function filtrosAtributosActivos(): array
    {
        return collect($this->filtrosAtributos)
            ->only($this->atributosVisibles)
            ->all();
    }

    public function abrirCliente(): void
    {
        $this->mostrarCliente = true;
        $this->mostrandoRegistroCliente = false;
        $this->refrescarResultadosCliente();
    }

    public function cerrarCliente(): void
    {
        $this->mostrarCliente = false;
        $this->mostrandoRegistroCliente = false;
        $this->busquedaCliente = '';
        $this->resultadosCliente = [];
        $this->limpiarFormularioCliente();
    }

    public function updatedBusquedaCliente(): void
    {
        $this->refrescarResultadosCliente();
    }

    public function seleccionarCliente(int $clienteId): void
    {
        $this->saldoFavor = '';
        $cliente = Cliente::query()->whereKey($clienteId)->first();

        if (! $cliente instanceof Cliente) {
            Notification::make()
                ->title('Cliente no disponible')
                ->warning()
                ->send();

            return;
        }

        $this->cerrarCliente();
        $this->tomarCliente($cliente);
    }

    public function confirmarListaPrecio(string $lista): void
    {
        $tipo = TipoPrecioCliente::tryFrom($lista);

        if ($tipo === null || $this->clientePendienteId === null) {
            return;
        }

        $cliente = Cliente::query()->whereKey($this->clientePendienteId)->first();

        if (! $cliente instanceof Cliente) {
            $this->cancelarEleccionPrecio();

            return;
        }

        $this->clienteId = $cliente->id;
        $this->listaPrecio = $tipo->value;
        $this->saldoFavor = '';
        $this->cerrarEleccionPrecio();
        $this->aplicarListaPrecio();
    }

    public function cancelarEleccionPrecio(): void
    {
        $this->cerrarEleccionPrecio();
    }

    public function cambiarListaPrecio(string $lista): void
    {
        $tipo = TipoPrecioCliente::tryFrom($lista);

        if ($tipo === null || $tipo->value === $this->listaPrecio) {
            return;
        }

        $this->listaPrecio = $tipo->value;
        $this->aplicarListaPrecio();
    }

    public function quitarCliente(): void
    {
        $this->saldoFavor = '';
        $this->clienteId = null;
        $this->listaPrecio = TipoPrecioCliente::Menor->value;
        $this->cerrarEleccionPrecio();
        $this->aplicarListaPrecio();
    }

    public function abrirRegistroCliente(): void
    {
        if (! $this->usuario()->can('create', Cliente::class)) {
            Notification::make()
                ->title('No podés registrar clientes')
                ->warning()
                ->send();

            return;
        }

        $texto = trim($this->busquedaCliente);
        $this->limpiarFormularioCliente();

        if ($texto !== '') {
            if (preg_match('/^\d[\d.\-]*$/', $texto) === 1) {
                $this->clienteDocumento = $texto;
            } else {
                $partes = preg_split('/\s+/u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [];

                if (count($partes) >= 2) {
                    $this->clienteApellidos = (string) array_pop($partes);
                    $this->clienteNombres = implode(' ', $partes);
                } else {
                    $this->clienteNombres = $texto;
                }
            }
        }

        $this->mostrandoRegistroCliente = true;
    }

    public function volverBusquedaCliente(): void
    {
        $this->mostrandoRegistroCliente = false;
        $this->limpiarFormularioCliente();
        $this->refrescarResultadosCliente();
    }

    public function registrarClienteRapido(): void
    {
        if (! $this->usuario()->can('create', Cliente::class)) {
            Notification::make()
                ->title('No podés registrar clientes')
                ->warning()
                ->send();

            return;
        }

        try {
            $resultado = app(RegistrarClienteRapido::class)->handle([
                'tipo' => $this->clienteTipo,
                'nombres' => $this->clienteNombres,
                'apellidos' => $this->clienteApellidos,
                'razon_social' => $this->clienteRazonSocial,
                'documento' => $this->clienteDocumento,
                'telefono' => $this->clienteTelefono,
                'email' => $this->clienteEmail,
            ]);
        } catch (ValidationException $e) {
            Notification::make()
                ->title('No se registró el cliente')
                ->body(collect($e->errors())->flatten()->first() ?? 'Revisá los datos.')
                ->danger()
                ->send();

            return;
        }

        $cliente = $resultado['cliente'];
        $abreEleccion = $cliente->tipo_precio === TipoPrecioCliente::Mayor
            && $this->clienteId !== $cliente->id;
        $this->cerrarCliente();
        $this->tomarCliente($cliente);

        if ($abreEleccion) {
            return;
        }

        Notification::make()
            ->title($resultado['existia'] ? 'Ya estaba registrado' : 'Cliente listo')
            ->body($resultado['existia']
                ? 'Lo seleccionamos para esta venta.'
                : $cliente->nombre_mostrar)
            ->success()
            ->send();
    }

    protected function refrescarResultadosCliente(): void
    {
        $this->resultadosCliente = $this->buscadorClientes()->sugerir($this->busquedaCliente)['items'];
    }

    protected function limpiarFormularioCliente(): void
    {
        $this->clienteTipo = TipoCliente::PersonaNatural->value;
        $this->clienteNombres = '';
        $this->clienteApellidos = '';
        $this->clienteRazonSocial = '';
        $this->clienteDocumento = '';
        $this->clienteTelefono = '';
        $this->clienteEmail = '';
    }

    protected function clienteSeleccionado(): ?Cliente
    {
        if ($this->clienteId === null) {
            return null;
        }

        return Cliente::query()->with(['documentos.tipoDocumentoIdentidad'])->find($this->clienteId);
    }

    protected function buscadorClientes(): BuscarClientesMostrador
    {
        return app(BuscarClientesMostrador::class);
    }

    protected function buscador(): BuscarProductosMostrador
    {
        return app(BuscarProductosMostrador::class);
    }

    protected function actualizarAlmacenPorDefecto(): void
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
    protected function almacenesDeSucursal(): Collection
    {
        if (! $this->sucursalId) {
            return collect();
        }

        $ids = SucursalAlmacen::query()
            ->where('sucursal_id', $this->sucursalId)
            ->pluck('almacen_id');

        return Almacen::query()->whereIn('id', $ids)->orderBy('nombre')->get();
    }

    protected function cajaAbierta(): ?CajaSesion
    {
        $usuario = auth('tenant')->user();

        if (! $usuario instanceof TenantUser || ! $this->sucursalId) {
            return null;
        }

        return CajaSesion::query()
            ->where('sucursal_id', $this->sucursalId)
            ->where('user_id', $usuario->id)
            ->where('estado_sesion', EstadoCajaSesion::Abierta)
            ->whereDate('fecha_apertura', now()->toDateString())
            ->first();
    }

    protected function disponibleDe(int $varianteId): string
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

    protected function totalTentativo(): string
    {
        $total = '0.00';

        foreach ($this->lineas as $linea) {
            $total = Montos::sumar($total, $this->totalLinea($linea), 2);
        }

        $descuentoVenta = $this->descuentoVentaAplicado($total);

        return Montos::sumar($total, bcmul($descuentoVenta, '-1', 2), 2);
    }

    protected function descuentoVentaAplicado(string $base): string
    {
        if ($this->tipoDescuentoVenta === '' || trim($this->valorDescuentoVenta) === '') {
            return '0.00';
        }

        try {
            if ($this->tipoDescuentoVenta === TipoDescuento::Porcentaje->value) {
                return Montos::porcentaje($base, (string) $this->valorDescuentoVenta, 2);
            }

            if ($this->tipoDescuentoVenta === TipoDescuento::Importe->value) {
                $valor = Montos::dos((string) $this->valorDescuentoVenta);

                return Montos::comparar($valor, $base, 2) === 1 ? $base : $valor;
            }
        } catch (\Throwable) {
            return '0.00';
        }

        return '0.00';
    }

    protected function subtotalBrutoTentativo(): string
    {
        $total = '0.00';

        foreach ($this->lineas as $linea) {
            try {
                $bruto = Montos::multiplicar(
                    (string) ($linea['cantidad'] ?: '0'),
                    (string) ($linea['precio_unitario'] ?: '0'),
                    2,
                );
                $total = Montos::sumar($total, $bruto, 2);
            } catch (\Throwable) {
                continue;
            }
        }

        return $total;
    }

    protected function descuentoTotalTentativo(): string
    {
        return Montos::sumar(
            $this->subtotalBrutoTentativo(),
            bcmul($this->totalTentativo(), '-1', 2),
            2,
        );
    }

    /**
     * @return list<string>
     */
    protected function totalesPorLinea(): array
    {
        $totales = [];

        foreach ($this->lineas as $linea) {
            $totales[] = $this->totalLinea($linea);
        }

        return $totales;
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
    protected function totalLinea(array $linea): string
    {
        return $this->totalDeLinea($linea);
    }

    protected function totalRecibido(): string
    {
        $total = $this->saldoFavorTentativo();

        foreach ($this->pagos as $pago) {
            $monto = trim((string) ($pago['monto'] ?? ''));

            if ($monto === '' || preg_match('/^\d+(\.\d+)?$/', $monto) !== 1) {
                continue;
            }

            $total = Montos::sumar($total, Montos::dos($monto), 2);
        }

        return $total;
    }

    private function saldoFavorTentativo(): string
    {
        return preg_match('/^\d{1,8}(\.\d{1,2})?$/', $this->saldoFavor) === 1
            ? Montos::dos($this->saldoFavor) : '0.00';
    }

    /**
     * @return array{estado: string, monto: string}
     */
    protected function diferenciaCobros(): array
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

    protected function unidadesEnCarrito(): string
    {
        $porSimbolo = [];

        foreach ($this->lineas as $linea) {
            $cant = trim((string) ($linea['cantidad'] ?? '0'));

            if ($cant === '' || preg_match('/^\d+(\.\d+)?$/', $cant) !== 1) {
                continue;
            }

            $simbolo = trim((string) ($linea['unidad_simbolo'] ?? '')) ?: 'pza';
            $porSimbolo[$simbolo] = Montos::sumar(
                $porSimbolo[$simbolo] ?? '0.0000',
                Montos::redondear($cant, 4),
                4,
            );
        }

        if ($porSimbolo === []) {
            return '0';
        }

        $partes = [];

        foreach ($porSimbolo as $simbolo => $total) {
            $partes[] = Montos::sinCeros($total).' '.$simbolo;
        }

        return implode(' · ', $partes);
    }

    protected function recalcularTotalesUi(): void
    {
        // Livewire re-renders; totalTentativo() recalcula.
    }

    protected function usuario(): TenantUser
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
