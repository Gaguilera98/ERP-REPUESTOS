<?php

namespace App\Ventas;

use App\Caja\CongelarTurnosVencidos;
use App\Compras\Montos;
use App\Enums\CondicionVenta;
use App\Enums\EstadoCajaSesion;
use App\Enums\EstadoPago;
use App\Enums\EstadoVenta;
use App\Enums\MetodoPago;
use App\Enums\SentidoPago;
use App\Enums\TipoComprobante;
use App\Enums\TipoDescuento;
use App\Enums\TipoMovimientoInventario;
use App\Enums\TipoPrecioCliente;
use App\Inventario\FactorUnidad;
use App\Models\CajaSesion;
use App\Models\Cliente;
use App\Models\InventarioStock;
use App\Models\MovimientoInventario;
use App\Models\NegocioConfiguracion;
use App\Models\Pago;
use App\Models\PagoAplicacion;
use App\Models\ProductoVariante;
use App\Models\SucursalAlmacen;
use App\Models\TenantUser;
use App\Models\UnidadMedida;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Ventas\Pagos\MetodoPagoFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompletarVentaContado
{
    public function __construct(
        private ReservarCodigoVenta $codigos,
        private FactorUnidad $factores,
        private MetodoPagoFactory $metodosPago,
        private PrecioDeLista $precios,
    ) {}

    /**
     * @param  array{
     *     sucursal_id: mixed,
     *     almacen_id: mixed,
     *     cliente_id?: mixed,
     *     tipo_precio?: mixed,
     *     notes_internas?: mixed,
     *     tipo_descuento?: mixed,
     *     valor_descuento?: mixed,
     *     supervisor_descuento_id?: mixed,
     *     lineas: list<array{
     *         producto_variante_id: mixed,
     *         unidad_medida_id?: mixed,
     *         cantidad: mixed,
     *         tipo_descuento?: mixed,
     *         valor_descuento?: mixed,
     *         supervisor_descuento_id?: mixed
     *     }>,
     *     pagos: list<array{
     *         metodo_pago: mixed,
     *         monto: mixed,
     *         numero_referencia_banco?: mixed,
     *         qr_id_transaccion_banco?: mixed,
     *         comprobante_archivo_path?: mixed
     *     }>
     * }  $data
     * @return array{venta: Venta, alertas_perdida: list<array{sku: string, precio_neto: string, precio_costo: string}>}
     */
    public function completar(array $data, TenantUser $usuario): array
    {
        return $this->registrar($data, $usuario, CondicionVenta::Contado);
    }

    public function venderFiado(array $data, TenantUser $usuario): array
    {
        abort_unless($usuario->can('create', Venta::class), 403);

        return $this->registrar($data, $usuario, CondicionVenta::Credito);
    }

    private function registrar(array $data, TenantUser $usuario, CondicionVenta $condicion): array
    {
        return DB::transaction(function () use ($data, $usuario, $condicion): array {
            $sucursalId = $this->id($data['sucursal_id'] ?? null, 'sucursal_id', 'Elegí la sucursal.');
            $almacenId = $this->id($data['almacen_id'] ?? null, 'almacen_id', 'Elegí el almacén.');

            if (! SucursalAlmacen::query()
                ->where('sucursal_id', $sucursalId)
                ->where('almacen_id', $almacenId)
                ->exists()) {
                throw ValidationException::withMessages([
                    'almacen_id' => 'Ese almacén no está asignado a la sucursal.',
                ]);
            }

            app(CongelarTurnosVencidos::class)->de($usuario, $sucursalId);

            $caja = CajaSesion::query()
                ->where('sucursal_id', $sucursalId)
                ->where('user_id', $usuario->id)
                ->where('estado_sesion', EstadoCajaSesion::Abierta)
                ->lockForUpdate()
                ->first();

            if (! $caja instanceof CajaSesion) {
                throw ValidationException::withMessages([
                    'sucursal_id' => 'Abrí la caja de hoy en esa sucursal antes de vender.',
                ]);
            }

            $clienteId = $this->idOpcional($data['cliente_id'] ?? null, 'cliente_id');
            if ($condicion === CondicionVenta::Credito && $clienteId === null) {
                throw ValidationException::withMessages(['cliente_id' => 'Elegí un cliente para vender a crédito.']);
            }
            $cliente = null;

            if ($clienteId !== null) {
                $cliente = Cliente::query()->find($clienteId);

                if (! $cliente instanceof Cliente) {
                    throw ValidationException::withMessages([
                        'cliente_id' => 'Ese cliente no es de este negocio.',
                    ]);
                }
            }

            $config = NegocioConfiguracion::query()->first();
            $topePorcentaje = Montos::dos($config?->descuento_max_porcentaje ?? '10');
            $topeImporte = Montos::dos($config?->descuento_max_importe ?? '0');

            $lineasRaw = $data['lineas'] ?? null;

            if (! is_array($lineasRaw) || $lineasRaw === []) {
                throw ValidationException::withMessages([
                    'lineas' => 'Agregá al menos un producto.',
                ]);
            }

            $lista = $this->listaPedida($data, $cliente);
            $lineasPreparadas = [];
            $varianteIds = [];

            foreach (array_values($lineasRaw) as $indice => $linea) {
                if (! is_array($linea)) {
                    throw ValidationException::withMessages([
                        'lineas' => 'Agregá al menos un producto.',
                    ]);
                }

                $lineasPreparadas[] = $this->prepararLinea(
                    $linea,
                    $indice,
                    $lista,
                    $topePorcentaje,
                    $topeImporte,
                );
                $varianteIds[] = $lineasPreparadas[$indice]['producto_variante_id'];
            }

            $varianteIds = collect($varianteIds)->unique()->sort()->values()->all();

            $variantes = ProductoVariante::query()
                ->with('productoMaestro')
                ->whereIn('id', $varianteIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($varianteIds as $varianteId) {
                InventarioStock::query()->firstOrCreate([
                    'producto_variante_id' => $varianteId,
                    'almacen_id' => $almacenId,
                ]);
            }

            $stocks = InventarioStock::query()
                ->where('almacen_id', $almacenId)
                ->whereIn('producto_variante_id', $varianteIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('producto_variante_id');

            $subtotal = '0.00';
            $descuentoTotal = '0.00';
            $alertas = [];

            foreach ($lineasPreparadas as $i => $linea) {
                $variante = $variantes->get($linea['producto_variante_id']);

                if (! $variante instanceof ProductoVariante) {
                    throw ValidationException::withMessages([
                        "lineas.{$i}.producto_variante_id" => 'Ese producto no es de este negocio.',
                    ]);
                }

                $stock = $stocks->get($variante->id);

                if (! $stock instanceof InventarioStock) {
                    throw ValidationException::withMessages([
                        "lineas.{$i}.producto_variante_id" => 'No hay saldo de esa pieza en el almacén.',
                    ]);
                }

                $disponible = Montos::sumar(
                    (string) $stock->cantidad_actual,
                    bcmul((string) $stock->cantidad_reservada, '-1', 4),
                    4,
                );

                if (Montos::comparar($linea['cantidad_base'], $disponible, 4) === 1) {
                    throw ValidationException::withMessages([
                        "lineas.{$i}.cantidad" => 'No hay stock suficiente de '.$variante->sku.'.',
                    ]);
                }

                $subtotal = Montos::sumar($subtotal, Montos::multiplicar($linea['cantidad_base'], $linea['precio_unitario'], 2), 2);
                $descuentoTotal = Montos::sumar($descuentoTotal, $linea['descuento_aplicado'], 2);

                $precioNetoUnidad = Montos::comparar($linea['cantidad_base'], '0', 4) === 1
                    ? Montos::dividir($linea['total_linea'], $linea['cantidad_base'], 2)
                    : '0.00';

                if (Montos::comparar((string) $variante->precio_costo, $precioNetoUnidad, 4) === 1) {
                    $alertas[] = [
                        'sku' => (string) $variante->sku,
                        'precio_neto' => $precioNetoUnidad,
                        'precio_costo' => Montos::cuatro((string) $variante->precio_costo),
                    ];
                }

                $lineasPreparadas[$i]['variante'] = $variante;
                $lineasPreparadas[$i]['stock'] = $stock;
                $lineasPreparadas[$i]['precio_costo_snapshot'] = Montos::cuatro((string) $variante->precio_costo);
            }

            $base = Montos::sumar($subtotal, bcmul($descuentoTotal, '-1', 2), 2);
            $descuentoVenta = $this->resolverDescuento(
                $data['tipo_descuento'] ?? null,
                $data['valor_descuento'] ?? null,
                $data['supervisor_descuento_id'] ?? null,
                $base,
                $topePorcentaje,
                $topeImporte,
                'tipo_descuento',
                'valor_descuento',
                'supervisor_descuento_id',
            );
            $descuentoTotal = Montos::sumar($descuentoTotal, $descuentoVenta['aplicado'], 2);
            $base = Montos::sumar($base, bcmul($descuentoVenta['aplicado'], '-1', 2), 2);
            $total = $base;

            $pagosRaw = $data['pagos'] ?? null;

            if (! is_array($pagosRaw) || ($pagosRaw === [] && $condicion === CondicionVenta::Contado)) {
                throw ValidationException::withMessages([
                    'pagos' => 'Registrá al menos un cobro.',
                ]);
            }

            $pagosPreparados = [];
            $sumaPagos = '0.00';

            foreach (array_values($pagosRaw) as $indice => $pago) {
                if (! is_array($pago)) {
                    throw ValidationException::withMessages([
                        'pagos' => 'Registrá al menos un cobro.',
                    ]);
                }

                $metodoRaw = $pago['metodo_pago'] ?? null;

                try {
                    $handler = $this->metodosPago->make(is_string($metodoRaw) ? $metodoRaw : (string) $metodoRaw);
                } catch (ValidationException) {
                    throw ValidationException::withMessages([
                        "pagos.{$indice}.metodo_pago" => 'Elegí un método de pago válido.',
                    ]);
                }

                $preparado = $handler->preparar($pago, $indice, $caja);
                $pagosPreparados[] = $preparado;
                $sumaPagos = Montos::sumar($sumaPagos, $preparado['monto'], 2);
            }

            $comparacion = Montos::comparar($sumaPagos, $total, 2);
            if ($condicion === CondicionVenta::Credito && $comparacion >= 0) {
                throw ValidationException::withMessages(['pagos' => 'Para vender a crédito tiene que quedar un saldo pendiente.']);
            }

            if ($condicion === CondicionVenta::Contado && $comparacion !== 0) {
                throw ValidationException::withMessages([
                    'pagos' => 'La suma de los cobros tiene que ser exactamente el total ('.$total.').',
                ]);
            }

            $codigo = $this->codigos->reservar();

            $notes = $data['notes_internas'] ?? null;
            $notes = is_string($notes) ? trim($notes) : null;

            $venta = Venta::query()->create([
                'codigo' => $codigo,
                'sucursal_id' => $sucursalId,
                'almacen_id' => $almacenId,
                'talonario_id' => null,
                'cliente_id' => $clienteId,
                'user_id' => $usuario->id,
                'caja_sesion_id' => $caja->id,
                'condicion' => $condicion,
                'estado' => EstadoVenta::Completada,
                'vence_en' => null,
                'tipo_comprobante' => TipoComprobante::NotaVenta,
                'numero_comprobante' => null,
                'subtotal' => $subtotal,
                'tipo_descuento' => $descuentoVenta['tipo'],
                'valor_descuento' => $descuentoVenta['valor'],
                'descuento' => $descuentoTotal,
                'supervisor_descuento_id' => $descuentoVenta['supervisor_id'],
                'total' => $total,
                'notes_internas' => $notes !== '' ? $notes : null,
            ]);

            foreach ($lineasPreparadas as $linea) {
                /** @var ProductoVariante $variante */
                $variante = $linea['variante'];
                /** @var InventarioStock $stock */
                $stock = $linea['stock'];

                $previo = Montos::cuatro((string) $stock->cantidad_actual);
                $posterior = Montos::sumar($previo, bcmul($linea['cantidad_base'], '-1', 4), 4);

                $stock->cantidad_actual = $posterior;
                $stock->save();

                MovimientoInventario::query()->create([
                    'producto_variante_id' => $variante->id,
                    'almacen_id' => $almacenId,
                    'user_id' => $usuario->id,
                    'tipo_movimiento' => TipoMovimientoInventario::SalidaVenta,
                    'cantidad' => $linea['cantidad_base'],
                    'stock_previo' => $previo,
                    'stock_posterior' => $posterior,
                    'costo_unitario' => $linea['precio_costo_snapshot'],
                    'costo_promedio_resultante' => $linea['precio_costo_snapshot'],
                    'referencia_tipo' => MovimientoInventario::REFERENCIA_VENTA,
                    'referencia_id' => $venta->id,
                ]);

                VentaDetalle::query()->create([
                    'venta_id' => $venta->id,
                    'producto_variante_id' => $variante->id,
                    'unidad_medida_id' => $linea['unidad_medida_id'],
                    'factor_a_base' => $linea['factor_a_base'],
                    'cantidad' => $linea['cantidad'],
                    'cantidad_base' => $linea['cantidad_base'],
                    'precio_unitario' => $linea['precio_unitario'],
                    'precio_costo_snapshot' => $linea['precio_costo_snapshot'],
                    'tipo_descuento' => $linea['tipo_descuento'],
                    'valor_descuento' => $linea['valor_descuento'],
                    'descuento_aplicado' => $linea['descuento_aplicado'],
                    'supervisor_descuento_id' => $linea['supervisor_descuento_id'],
                    'total_linea' => $linea['total_linea'],
                ]);
            }

            foreach ($pagosPreparados as $pagoData) {
                $this->guardarCobro($venta, $pagoData);
            }

            return [
                'venta' => $venta->refresh()->load('detalles'),
                'alertas_perdida' => $alertas,
            ];
        });
    }

    public function abonarEnEfectivo(string $codigo, int $sucursalId, string $monto, TenantUser $usuario): string
    {
        abort_unless($usuario->can('create', Venta::class), 403);

        return DB::transaction(function () use ($codigo, $sucursalId, $monto, $usuario): string {
            app(CongelarTurnosVencidos::class)->de($usuario, $sucursalId);
            $caja = CajaSesion::query()
                ->where('sucursal_id', $sucursalId)
                ->where('user_id', $usuario->id)
                ->where('estado_sesion', EstadoCajaSesion::Abierta)
                ->lockForUpdate()
                ->first();

            if (! $caja instanceof CajaSesion || ! $caja->esDeHoy()) {
                throw ValidationException::withMessages(['codigoAbono' => 'Abrí la caja de hoy para registrar el abono.']);
            }

            $venta = Venta::query()->where('codigo', $codigo)
                ->where('sucursal_id', $sucursalId)
                ->where('condicion', CondicionVenta::Credito)
                ->where('estado', EstadoVenta::Completada)
                ->lockForUpdate()
                ->first();

            if (! $venta instanceof Venta) {
                throw ValidationException::withMessages(['codigoAbono' => 'No hay una venta a crédito con ese código en esta sucursal.']);
            }
            abort_unless($usuario->can('view', $venta), 403);

            $saldo = $venta->saldoPendiente();
            $pago = $this->metodosPago->make(MetodoPago::Efectivo)->preparar(['monto' => $monto], 0, $caja);

            if (Montos::comparar($pago['monto'], $saldo, 2) > 0) {
                throw ValidationException::withMessages(['montoAbono' => 'El abono supera el saldo pendiente ('.$saldo.').']);
            }

            $this->guardarCobro($venta, $pago);

            return bcsub($saldo, $pago['monto'], 2);
        });
    }

    private function guardarCobro(Venta $venta, array $pagoData): void
    {
        $pago = Pago::query()->create([
            'cliente_id' => $venta->cliente_id,
            'caja_sesion_id' => $pagoData['caja_sesion_id'],
            'sentido' => SentidoPago::Cobro,
            'metodo_pago' => $pagoData['metodo_pago'],
            'monto' => $pagoData['monto'],
            'comprobante_archivo_path' => $pagoData['comprobante_archivo_path'],
            'numero_referencia_banco' => $pagoData['numero_referencia_banco'],
            'qr_id_transaccion_banco' => $pagoData['qr_id_transaccion_banco'],
            'estado_pago' => EstadoPago::Aprobado,
            'fecha_pago' => now(),
        ]);

        PagoAplicacion::query()->create([
            'pago_id' => $pago->id,
            'venta_id' => $venta->id,
            'nota_credito_id' => null,
            'aplicacion_origen_id' => null,
            'monto' => $pagoData['monto'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $linea
     * @return array{
     *     producto_variante_id: int,
     *     unidad_medida_id: int,
     *     factor_a_base: string,
     *     cantidad: string,
     *     cantidad_base: string,
     *     precio_unitario: string,
     *     tipo_descuento: TipoDescuento|null,
     *     valor_descuento: string,
     *     descuento_aplicado: string,
     *     supervisor_descuento_id: int|null,
     *     total_linea: string
     * }
     */
    private function prepararLinea(
        array $linea,
        int $indice,
        TipoPrecioCliente $lista,
        string $topePorcentaje,
        string $topeImporte,
    ): array {
        $varianteId = $this->id(
            $linea['producto_variante_id'] ?? null,
            "lineas.{$indice}.producto_variante_id",
            'Elegí el producto.',
        );

        $variante = ProductoVariante::query()->with('productoMaestro')->find($varianteId);

        if (! $variante instanceof ProductoVariante || ! $variante->productoMaestro) {
            throw ValidationException::withMessages([
                "lineas.{$indice}.producto_variante_id" => 'Ese producto no es de este negocio.',
            ]);
        }

        $unidadId = array_key_exists('unidad_medida_id', $linea) && $linea['unidad_medida_id'] !== null && $linea['unidad_medida_id'] !== ''
            ? $this->id($linea['unidad_medida_id'], "lineas.{$indice}.unidad_medida_id", 'Elegí la unidad.')
            : (int) $variante->productoMaestro->unidad_base_id;

        $unidad = UnidadMedida::query()->find($unidadId);

        if (! $unidad instanceof UnidadMedida) {
            throw ValidationException::withMessages([
                "lineas.{$indice}.unidad_medida_id" => 'Esa unidad no es de este negocio.',
            ]);
        }

        $cantidad = $this->cantidad($linea['cantidad'] ?? null, "lineas.{$indice}.cantidad");

        if (! $unidad->admiteCantidad($cantidad)) {
            throw ValidationException::withMessages([
                "lineas.{$indice}.cantidad" => 'Esa unidad no acepta fracciones.',
            ]);
        }

        $factor = $this->factores->haciaBase($variante->productoMaestro, $unidadId);
        $cantidadBase = Montos::multiplicar($cantidad, $factor, 4);

        $precioUnitario = $this->precioDeLista($variante, $lista);
        $bruto = Montos::multiplicar($cantidadBase, $precioUnitario, 2);

        $descuento = $this->resolverDescuento(
            $linea['tipo_descuento'] ?? null,
            $linea['valor_descuento'] ?? null,
            $linea['supervisor_descuento_id'] ?? null,
            $bruto,
            $topePorcentaje,
            $topeImporte,
            "lineas.{$indice}.tipo_descuento",
            "lineas.{$indice}.valor_descuento",
            "lineas.{$indice}.supervisor_descuento_id",
        );

        $totalLinea = Montos::sumar($bruto, bcmul($descuento['aplicado'], '-1', 2), 2);

        if (Montos::comparar($totalLinea, '0', 2) === -1) {
            throw ValidationException::withMessages([
                "lineas.{$indice}.valor_descuento" => 'El descuento no puede dejar la línea en negativo.',
            ]);
        }

        return [
            'producto_variante_id' => $varianteId,
            'unidad_medida_id' => $unidadId,
            'factor_a_base' => $factor,
            'cantidad' => $cantidad,
            'cantidad_base' => $cantidadBase,
            'precio_unitario' => $precioUnitario,
            'tipo_descuento' => $descuento['tipo'],
            'valor_descuento' => $descuento['valor'],
            'descuento_aplicado' => $descuento['aplicado'],
            'supervisor_descuento_id' => $descuento['supervisor_id'],
            'total_linea' => $totalLinea,
        ];
    }

    /**
     * @return array{tipo: TipoDescuento|null, valor: string, aplicado: string, supervisor_id: int|null}
     */
    private function resolverDescuento(
        mixed $tipoRaw,
        mixed $valorRaw,
        mixed $supervisorRaw,
        string $base,
        string $topePorcentaje,
        string $topeImporte,
        string $campoTipo,
        string $campoValor,
        string $campoSupervisor,
    ): array {
        $supervisorId = $this->idOpcional($supervisorRaw, $campoSupervisor);

        if ($tipoRaw === null || $tipoRaw === '') {
            return [
                'tipo' => null,
                'valor' => '0.00',
                'aplicado' => '0.00',
                'supervisor_id' => $supervisorId,
            ];
        }

        $tipo = TipoDescuento::tryFrom(is_string($tipoRaw) ? $tipoRaw : (string) $tipoRaw);

        if ($tipo === null) {
            throw ValidationException::withMessages([
                $campoTipo => 'El descuento es porcentaje o importe.',
            ]);
        }

        $valor = $this->montoDos($valorRaw ?? null, $campoValor);
        $aplicado = '0.00';

        if ($tipo === TipoDescuento::Porcentaje) {
            if (Montos::comparar($valor, '100', 2) === 1) {
                throw ValidationException::withMessages([
                    $campoValor => 'El porcentaje no puede pasar de 100.',
                ]);
            }

            $aplicado = Montos::porcentaje($base, $valor, 2);

            if (Montos::comparar($valor, $topePorcentaje, 2) === 1 && $supervisorId === null) {
                throw ValidationException::withMessages([
                    $campoSupervisor => 'Ese descuento pasa el tope. Pedí autorización de un supervisor.',
                ]);
            }
        } else {
            $aplicado = $valor;

            if (Montos::comparar($aplicado, $base, 2) === 1) {
                throw ValidationException::withMessages([
                    $campoValor => 'El descuento no puede dejar el total en negativo.',
                ]);
            }

            if (Montos::comparar($valor, $topeImporte, 2) === 1 && $supervisorId === null) {
                throw ValidationException::withMessages([
                    $campoSupervisor => 'Ese descuento pasa el tope. Pedí autorización de un supervisor.',
                ]);
            }
        }

        if ($supervisorId !== null && ! TenantUser::query()->whereKey($supervisorId)->exists()) {
            throw ValidationException::withMessages([
                $campoSupervisor => 'Ese supervisor no es de este negocio.',
            ]);
        }

        return [
            'tipo' => $tipo,
            'valor' => $valor,
            'aplicado' => $aplicado,
            'supervisor_id' => $supervisorId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function listaPedida(array $data, ?Cliente $cliente): TipoPrecioCliente
    {
        $raw = $data['tipo_precio'] ?? null;

        if ($raw === null || $raw === '') {
            return $cliente?->tipo_precio ?? TipoPrecioCliente::Menor;
        }

        $lista = TipoPrecioCliente::tryFrom(is_string($raw) ? $raw : '');

        if ($lista === null) {
            throw ValidationException::withMessages([
                'tipo_precio' => 'La lista es menor o mayor.',
            ]);
        }

        return $lista;
    }

    private function precioDeLista(ProductoVariante $variante, TipoPrecioCliente $lista): string
    {
        return $this->precios->de($variante, $lista);
    }

    private function id(mixed $valor, string $campo, string $mensaje): int
    {
        if (is_int($valor) && $valor > 0) {
            return $valor;
        }

        if (is_string($valor) && ctype_digit($valor) && (int) $valor > 0) {
            return (int) $valor;
        }

        throw ValidationException::withMessages([
            $campo => $mensaje,
        ]);
    }

    private function idOpcional(mixed $valor, string $campo): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return $this->id($valor, $campo, 'Identificador inválido.');
    }

    private function cantidad(mixed $valor, string $campo): string
    {
        $texto = match (true) {
            is_int($valor) => (string) $valor,
            is_float($valor) => number_format($valor, 4, '.', ''),
            is_string($valor) => trim($valor),
            default => null,
        };

        if ($texto === null || preg_match('/^\d+(\.\d+)?$/', $texto) !== 1) {
            throw ValidationException::withMessages([
                $campo => 'La cantidad tiene que ser un número mayor a cero.',
            ]);
        }

        $cantidad = Montos::redondear($texto, 4);

        if (Montos::comparar($cantidad, '0', 4) !== 1) {
            throw ValidationException::withMessages([
                $campo => 'La cantidad tiene que ser un número mayor a cero.',
            ]);
        }

        return $cantidad;
    }

    private function montoDos(mixed $valor, string $campo): string
    {
        $texto = match (true) {
            is_int($valor) => (string) $valor,
            is_float($valor) => number_format($valor, 2, '.', ''),
            is_string($valor) => trim($valor),
            default => null,
        };

        if ($texto === null || preg_match('/^\d+(\.\d+)?$/', $texto) !== 1) {
            throw ValidationException::withMessages([
                $campo => 'El monto tiene que ser un número de cero para arriba.',
            ]);
        }

        return Montos::dos($texto);
    }
}
