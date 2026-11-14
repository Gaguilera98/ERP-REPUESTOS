<?php

use App\Caja\AbrirCajaSesion;
use App\Caja\ArqueoCajaSesion;
use App\Caja\CongelarTurnosVencidos;
use App\Catalogo\PublicarTipoCambio;
use App\Compras\GuardarOrdenCompra;
use App\Compras\ReasignarProveedorFlash;
use App\Compras\RecibirMercaderia;
use App\Enums\DevolucionComercialProducto;
use App\Enums\EstadoCajaSesion;
use App\Enums\EstadoPago;
use App\Enums\EstadoVenta;
use App\Enums\MetodoPago;
use App\Enums\ModoMoneda;
use App\Enums\SentidoPago;
use App\Enums\TipoCliente;
use App\Enums\TipoDescuento;
use App\Enums\TipoMovimientoInventario;
use App\Filament\Negocio\Pages\MostradorDelNegocio;
use App\Filament\Negocio\Resources\OrdenCompras\OrdenCompraResource;
use App\Filament\Negocio\Resources\OrdenCompras\Pages\ViewOrdenCompra;
use App\Livewire\Negocio\Caja\VentasDeSesion;
use App\Models\Almacen;
use App\Models\CajaSesion;
use App\Models\Categoria;
use App\Models\CategoriaAtributo;
use App\Models\Cliente;
use App\Models\ClienteDocumento;
use App\Models\Impuesto;
use App\Models\InventarioStock;
use App\Models\MovimientoInventario;
use App\Models\NegocioConfiguracion;
use App\Models\OrdenCompra;
use App\Models\Pago;
use App\Models\PagoAplicacion;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TipoDocumentoIdentidad;
use App\Models\UnidadMedida;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Ventas\AnularVenta;
use App\Ventas\CambiarProductoVenta;
use App\Ventas\CompletarVentaContado;
use App\Ventas\CompraFlash;
use App\Ventas\CorregirVenta;
use App\Ventas\GestionarReservas;
use App\Ventas\RegistrarDevolucion;
use App\Ventas\SaldoAFavorCliente;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::connection('pool_shared_1')->dropAllTables();

    $this->artisan('migrate', [
        '--database' => 'pool_shared_1',
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }
});

/**
 * @return array{
 *     cajero: TenantUser,
 *     supervisor: TenantUser,
 *     sucursal: Sucursal,
 *     almacen: Almacen,
 *     variante: ProductoVariante,
 *     ocasional: Proveedor
 * }
 */
function negocioConMostrador(string $dominio = 'venta'): array
{
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => $dominio]);
    tenancy()->initialize($tenant);

    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');

    $cajero = TenantUser::factory()->create();
    $cajero->assignRole('cajero');

    $sucursal = Sucursal::factory()->create(['nombre' => 'Centro']);
    $almacen = Almacen::factory()->create(['nombre' => 'Estante']);
    SucursalAlmacen::query()->create([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'es_principal' => true,
    ]);

    $variante = ProductoVariante::factory()->create([
        'sku' => '6204-2RS',
        'precio_venta' => '100.00',
        'precio_mayor' => '80.00',
        'precio_costo' => '40.0000',
    ]);

    $ocasional = Proveedor::query()->where('es_ocasional', true)->firstOrFail();

    return compact('cajero', 'supervisor', 'sucursal', 'almacen', 'variante', 'ocasional');
}

function cargarStock(ProductoVariante $variante, Almacen $almacen, TenantUser $usuario, Proveedor $ocasional, string $cantidad = '5'): void
{
    $orden = app(GuardarOrdenCompra::class)->crear([
        'proveedor_id' => $ocasional->id,
        'almacen_destino_id' => $almacen->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'unidad_medida_id' => $variante->productoMaestro->unidad_base_id,
            'cantidad_pedida' => $cantidad,
            'costo_unitario' => '40',
        ]],
    ], $usuario);

    app(RecibirMercaderia::class)->recibir($orden, [
        ['detalle_id' => $orden->detalles->first()->id, 'cantidad' => $cantidad],
    ], $usuario, true);
}

it('completa una venta de contado con código VNT, kardex y pago aplicado', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador();
    Impuesto::factory()->create(['nombre' => 'Impuesto de prueba', 'porcentaje' => '13.00', 'is_active' => true]);

    cargarStock($variante, $almacen, $cajero, $ocasional, '3');
    app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '50',
    ], $cajero);

    $resultado = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad' => '2',
        ]],
        'pagos' => [[
            'metodo_pago' => MetodoPago::Efectivo->value,
            'monto' => '200',
        ]],
    ], $cajero);

    $venta = $resultado['venta'];

    expect($venta->codigo)->toBe('VNT-000001')
        ->and($venta->estado)->toBe(EstadoVenta::Completada)
        ->and($venta->total)->toBe('200.00')
        ->and($venta->detalles)->toHaveCount(1)
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->value('cantidad_actual'))->toBe('1.0000')
        ->and(MovimientoInventario::query()->where('tipo_movimiento', TipoMovimientoInventario::SalidaVenta)->count())->toBe(1)
        ->and(Pago::query()->count())->toBe(1)
        ->and(PagoAplicacion::query()->where('venta_id', $venta->id)->count())->toBe(1)
        ->and(NegocioConfiguracion::query()->value('siguiente_numero_venta'))->toBe(2);
});

it('consulta mis ventas desde Mostrador por fecha, vendedor y sucursal aunque aún no tengan pagos', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen] = negocioConMostrador('mis-ventas');
    $cliente = Cliente::factory()->personaNatural()->create(['nombres' => 'Camila', 'apellidos' => 'Velasco']);
    $otraSucursal = Sucursal::factory()->create();
    SucursalAlmacen::query()->create(['sucursal_id' => $otraSucursal->id, 'almacen_id' => $almacen->id]);
    $ventas = Venta::factory()->state(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id]);
    $hoy = $ventas->create(['condicion' => 'credito', 'cliente_id' => $cliente->id, 'created_at' => now()]);
    $ayer = $ventas->create(['created_at' => now()->subDay()]);
    $deOtroVendedor = $ventas->create(['user_id' => TenantUser::factory()->create()->id, 'created_at' => now()]);
    $deOtraSucursal = $ventas->create(['sucursal_id' => $otraSucursal->id, 'created_at' => now()]);
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')->test(MostradorDelNegocio::class)
        ->mountAction('misVentas')->assertActionMounted('misVentas');

    Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sucursalMostradorId' => $sucursal->id])
        ->assertCanSeeTableRecords([$hoy])
        ->assertCanNotSeeTableRecords([$ayer, $deOtroVendedor, $deOtraSucursal])
        ->searchTable('Camila Velasco')
        ->assertCanSeeTableRecords([$hoy])
        ->searchTable('')
        ->filterTable('dia', ['fecha' => now()->subDay()->toDateString()])
        ->assertCanSeeTableRecords([$ayer])
        ->assertCanNotSeeTableRecords([$hoy, $deOtroVendedor, $deOtraSucursal]);
});

it('vende fiado en Mostrador y cobra el saldo en la caja de otro día sin volver a descontar stock', function (string $pagoInicial, string $saldo, string $abonoExcesivo) {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('fiado-abono');
    $cliente = Cliente::factory()->personaNatural()->create();
    cargarStock($variante, $almacen, $cajero, $ocasional, '3');
    $abrirCaja = app(AbrirCajaSesion::class);
    $primera = $abrirCaja->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')->test(MostradorDelNegocio::class)
        ->call('seleccionarResultado', $variante->id)
        ->set('ventaFiada', true)
        ->set('pagos.0.monto', $pagoInicial)
        ->call('completar')
        ->assertHasErrors(['cliente_id'])
        ->call('seleccionarCliente', $cliente->id)
        ->call('completar')
        ->assertHasNoErrors();

    $venta = Venta::query()->sole();
    expect($venta->condicion->value)->toBe('credito')
        ->and($venta->cliente_id)->toBe($cliente->id)
        ->and($venta->saldoPendiente())->toBe($saldo);
    expect(Pago::query()->where('caja_sesion_id', $primera->id)->pluck('monto')->all())
        ->toBe($pagoInicial === '' ? [] : ['30.00']);

    $this->travel(1)->day();
    $segunda = $abrirCaja->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);

    $detalle = TestAction::make('verDetalle')->table($venta);
    $abono = [$detalle, TestAction::make('registrarAbono')];
    $tabla = Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sucursalMostradorId' => $sucursal->id])
        ->filterTable('dia', ['fecha' => $venta->created_at->toDateString()])
        ->callAction($abono, data: ['monto' => $abonoExcesivo])
        ->assertNotified('No se pudo registrar el abono');

    expect($venta->saldoPendiente())->toBe($saldo);

    $tabla->fillForm(['monto' => $saldo])
        ->callMountedAction()
        ->assertNotified('Abono registrado');

    expect($venta->saldoPendiente())->toBe('0.00')
        ->and(Pago::query()->where('caja_sesion_id', $segunda->id)->sole()->monto)->toBe($saldo)
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->value('cantidad_actual'))->toBe('2.0000')
        ->and(MovimientoInventario::query()->where('tipo_movimiento', TipoMovimientoInventario::SalidaVenta)->count())->toBe(1);
})->with([
    'con pago parcial' => ['30', '70.00', '71'],
    'sin pago inicial' => ['', '100.00', '101'],
]);

it('anula una venta a crédito y conserva los movimientos de sus sesiones', function (bool $conAbono) {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('anulacion');
    $cliente = Cliente::factory()->personaNatural()->create();
    cargarStock($variante, $almacen, $cajero, $ocasional, '3');
    $abrirCaja = app(AbrirCajaSesion::class);
    $abrir = fn () => $abrirCaja->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $primera = $abrir();
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')->test(MostradorDelNegocio::class)
        ->call('seleccionarResultado', $variante->id)
        ->set('ventaFiada', true)
        ->set('pagos.0.monto', '')
        ->call('seleccionarCliente', $cliente->id)
        ->call('completar')
        ->assertHasNoErrors();

    $venta = Venta::query()->sole();
    $detalle = TestAction::make('verDetalle')->table($venta);
    $anular = [$detalle, TestAction::make('anularVenta')];
    $misVentas = fn () => Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sucursalMostradorId' => $sucursal->id])
        ->filterTable('dia', ['fecha' => $venta->created_at->toDateString()]);
    $misVentas()->assertActionHidden($anular);
    $cajero->givePermissionTo('Update:Venta');
    $arqueo = app(ArqueoCajaSesion::class);

    $this->travel(1)->day();
    if ($conAbono) {
        $segunda = $abrir();
        app(CompletarVentaContado::class)->abonarEnEfectivo($venta->codigo, $sucursal->id, '30', $cajero);
        $this->travel(1)->day();
        $misVentas()->callAction($anular, data: ['motivo' => 'Venta duplicada'])
            ->assertNotified('No se pudo anular la venta');
        expect($venta->refresh()->estado)->toBe(EstadoVenta::Completada);
        $tercera = $abrir();
    }

    $misVentas()->callAction($anular, data: ['motivo' => 'Venta duplicada'])
        ->assertNotified('Venta anulada');

    expect($venta->refresh()->estado)->toBe(EstadoVenta::Anulada)
        ->and($venta->saldoPendiente())->toBe('0.00')
        ->and($venta->motivo_anulacion)->toBe('Venta duplicada')
        ->and($venta->anulada_por_id)->toBe($cajero->id)
        ->and($venta->anulada_en)->not->toBeNull()
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->sole()->cantidad_actual)->toBe('3.0000')
        ->and(MovimientoInventario::query()->where('tipo_movimiento', TipoMovimientoInventario::Devolucion)->sole()->referencia_id)->toBe($venta->id);

    Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $primera->id])
        ->assertCanSeeTableRecords([$venta])
        ->assertSee('Anulada')
        ->assertActionHidden($anular);

    if ($conAbono) {
        $reembolso = Pago::query()->where('sentido', SentidoPago::Reembolso)->sole();
        expect($reembolso->caja_sesion_id)->toBe($tercera->id)
            ->and($reembolso->monto)->toBe('30.00')
            ->and($reembolso->aplicaciones()->sole()->aplicacion_origen_id)->toBe(PagoAplicacion::query()->whereHas('pago', fn ($pago) => $pago->where('sentido', SentidoPago::Cobro))->sole()->id)
            ->and($arqueo->de($segunda)['total_cobros'])->toBe('30.00')
            ->and($arqueo->de($segunda)['total_reembolsos'])->toBe('0.00')
            ->and($arqueo->de($tercera)['total_reembolsos'])->toBe('30.00');

        Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $tercera->id])
            ->assertCanSeeTableRecords([$venta]);
    } else {
        expect(Pago::query()->count())->toBe(0);
    }
})->with(['con abono posterior' => true, 'sin cobros' => false]);

it('corrige cantidades después del cierre y conserva el importe y cobro originales', function (string $cantidad, bool $credito, string $vigente, string $saldo, string $diferencia) {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('correccion');
    cargarStock($variante, $almacen, $cajero, $ocasional);
    $cliente = Cliente::factory()->personaNatural()->create();
    $abrir = app(AbrirCajaSesion::class);
    $cajaOriginal = $abrir->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $venta = app(CompletarVentaContado::class)->{$credito ? 'venderFiado' : 'completar'}([
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'cliente_id' => $cliente->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '2']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => $credito ? '30' : '200']],
    ], $cajero)['venta'];
    $cajaOriginal->forceFill(['estado_sesion' => 'cerrada', 'fecha_cierre' => now()])->save();
    $this->travel(1)->day();
    $cajaActual = $abrir->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '200'], $supervisor);
    $variante->update(['precio_venta' => '130']);
    $lineas = [['origen' => 0, 'producto_variante_id' => $variante->id, 'cantidad' => $cantidad]];
    $corregir = app(CorregirVenta::class);
    $resumen = $corregir->revisar($venta, $lineas);
    $revision = $corregir->revision($venta, $resumen);
    expect(fn () => $corregir->confirmar($venta, $lineas, 'Cantidad incorrecta', $revision, $cajero))
        ->toThrow(HttpException::class);

    $corregir->confirmar($venta, $lineas, 'Cantidad incorrecta', $revision, $supervisor);
    expect(fn () => $corregir->confirmar($venta, $lineas, 'Reintento', $revision, $supervisor))
        ->toThrow(ValidationException::class);

    $venta->refresh();
    $arqueo = app(ArqueoCajaSesion::class);
    expect($venta->total)->toBe('200.00')
        ->and($venta->detalles()->sole()->cantidad)->toBe('2.0000')
        ->and($venta->totalVigente())->toBe($vigente)
        ->and($venta->saldoPendiente())->toBe($saldo)
        ->and($venta->correcciones()->sole()->motivo)->toBe('Cantidad incorrecta')
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->sole()->cantidad_actual)->toBe(bcsub('5', $cantidad, 4))
        ->and($arqueo->de($cajaOriginal)['total_cobros'])->toBe($credito ? '30.00' : '200.00')
        ->and($arqueo->de($cajaOriginal)['total_reembolsos'])->toBe('0.00')
        ->and($arqueo->de($cajaActual)['total_reembolsos'])->toBe($cantidad === '1' ? $diferencia : '0.00')
        ->and($arqueo->de($cajaActual)['total_cobros'])->toBe($cantidad === '3' ? $diferencia : '0.00');

    if ($cantidad === '1' && ! $credito) {
        $venta->load(['cliente', 'usuario', 'sucursal', 'almacen', 'detalles.variante.productoMaestro.unidadBase', 'detalles.unidad', 'correcciones.usuario', 'ultimaCorreccion', 'aplicacionesPago.pago']);

        $this->view('filament.negocio.pages.caja.detalle-venta', [
            'venta' => $venta, 'moneda' => 'BOB', 'colorEstado' => 'success',
        ])->assertSeeInOrder([
            'Reembolso registrado', 'BOB 100.00', 'Productos después de la corrección',
            'Original', 'Actual', '2 pza', '1 pza', 'Productos de la venta original',
            'Cobros y reembolsos', 'Cobro', 'Reembolso', 'Total actual de la venta',
            'BOB 100.00', 'Cobrado', 'BOB 200.00', 'Reembolsado', 'BOB 100.00',
        ])->assertDontSee('Cobro neto');
    }

    if ($credito) {
        $venta->load(['cliente', 'usuario', 'sucursal', 'almacen', 'detalles.variante.productoMaestro.unidadBase', 'detalles.unidad', 'correcciones.usuario', 'ultimaCorreccion', 'aplicacionesPago.pago']);

        $this->view('filament.negocio.pages.caja.detalle-venta', [
            'venta' => $venta, 'moneda' => 'BOB', 'colorEstado' => 'success', 'saldoPendiente' => $saldo,
        ])->assertSeeInOrder(['Total actual de la venta', 'BOB 100.00', 'Pagado', 'BOB 30.00', 'Saldo pendiente', 'BOB 70.00']);
    }

    app(AnularVenta::class)->anular($venta, 'Cancelación posterior', $venta->cobradoNeto(), $supervisor);
    expect($venta->refresh()->estado)->toBe(EstadoVenta::Anulada)
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->sole()->cantidad_actual)->toBe('5.0000')
        ->and($arqueo->de($cajaActual)['total_reembolsos'])->toBe($credito ? '30.00' : ($cantidad === '3' ? '300.00' : '200.00'));
})->with([
    'devuelve solo el exceso' => ['1', false, '100.00', '0.00', '100.00'],
    'cobra solo la diferencia' => ['3', false, '300.00', '0.00', '100.00'],
    'conserva el abono y ajusta el saldo' => ['1', true, '100.00', '70.00', '0.00'],
]);

it('revisa y reemplaza un producto en el detalle sin registrar otro cobro', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('correccion-detalle');
    $reemplazo = ProductoVariante::factory()->create(['sku' => 'REEMPLAZO', 'precio_venta' => '100.00']);
    cargarStock($variante, $almacen, $cajero, $ocasional);
    cargarStock($reemplazo, $almacen, $cajero, $ocasional);
    $caja = app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $venta = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '100']],
    ], $cajero)['venta'];
    $cajero->givePermissionTo('Corregir:Venta');
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    $tabla = Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $caja->id])
        ->mountAction([TestAction::make('verDetalle')->table($venta), TestAction::make('corregirVenta')])
        ->fillForm([
            'lineas' => [['origen' => 0, 'producto_variante_id' => $reemplazo->id, 'cantidad' => '1']],
            'motivo' => '   ',
        ]);

    $componente = $tabla->instance();
    $wizard = $componente->getSchema($componente->getMountedActionSchemaName())->getComponents()[0];
    $tabla->call('callSchemaComponentMethod', $wizard->getKey(), 'nextStep', ['currentStepIndex' => 0])
        ->assertHasFormErrors(['motivo' => 'required'])
        ->assertSet('mountedActions.1.data.revision', null);

    $tabla->fillForm(['motivo' => 'Se registró la pieza equivocada'])
        ->call('callSchemaComponentMethod', $wizard->getKey(), 'nextStep', ['currentStepIndex' => 0])
        ->assertSet('mountedActions.1.data.resumen', fn (?string $resumen): bool => str_contains($resumen ?? '', 'Sin movimiento de dinero.'));
    expect($venta->correcciones()->exists())->toBeFalse();

    $tabla->fillForm(['confirmado' => true])->callMountedAction()->assertNotified('Corrección registrada');
    expect($venta->correcciones()->sole()->lineas[0]['producto_variante_id'])->toBe($reemplazo->id)
        ->and(Pago::query()->count())->toBe(1)
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->sole()->cantidad_actual)->toBe('5.0000')
        ->and(InventarioStock::query()->where('producto_variante_id', $reemplazo->id)->sole()->cantidad_actual)->toBe('4.0000');
});

it('aparta una reserva y la entrega o libera al vencer sin duplicar la seña', function (bool $entregar, string $accion) {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('reservas');
    cargarStock($variante, $almacen, $cajero, $ocasional, '3');
    $cliente = Cliente::factory()->personaNatural()->create();
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    Livewire::actingAs($cajero, 'tenant')->test(MostradorDelNegocio::class)
        ->call('seleccionarResultado', $variante->id)
        ->call('seleccionarCliente', $cliente->id)
        ->call('seleccionarOperacion', 'reserva')
        ->set('pagos.0.monto', '30')
        ->call('completar')->assertHasNoErrors();
    $venta = Venta::query()->sole();
    $stock = InventarioStock::query()->where('producto_variante_id', $variante->id)->sole();
    expect($venta->estado)->toBe(EstadoVenta::Reserva)
        ->and($venta->vence_en->isFuture())->toBeTrue()
        ->and($venta->vence_en->format('H:i'))->toBe('23:59')
        ->and($stock->cantidad_actual)->toBe('3.0000')
        ->and($stock->cantidad_reservada)->toBe('1.0000')
        ->and(MovimientoInventario::query()->where('tipo_movimiento', TipoMovimientoInventario::SalidaVenta)->count())->toBe(0);

    $reservas = app(GestionarReservas::class);
    if ($entregar) {
        $reservas->entregar($venta, '70.00', $cajero);
        expect(fn () => $reservas->entregar($venta, '70.00', $cajero))->toThrow(ValidationException::class);
        expect($venta->refresh()->estado)->toBe(EstadoVenta::Completada)
            ->and($stock->refresh()->cantidad_actual)->toBe('2.0000')
            ->and($venta->saldoPendiente())->toBe('0.00')
            ->and(Pago::query()->count())->toBe(2);
    } else {
        $this->travelTo($venta->vence_en->copy()->addMinute());
        if ($accion === 'consulta') {
            Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sucursalMostradorId' => $sucursal->id]);
        } elseif ($accion === 'venta fallida') {
            expect(fn () => app(CompletarVentaContado::class)->completar([
                'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id,
                'lineas' => [], 'pagos' => [],
            ], $cajero))->toThrow(ValidationException::class);
        } elseif ($accion === 'entrega vencida') {
            expect(fn () => $reservas->entregar($venta, '70.00', $cajero))->toThrow(ValidationException::class);
        } else {
            $reservas->procesarVencidas();
        }
        $reservas->procesarVencidas();
        expect($venta->refresh()->estado)->toBe(EstadoVenta::Vencida)
            ->and($stock->refresh()->cantidad_actual)->toBe('3.0000')
            ->and(Pago::query()->count())->toBe(1)
            ->and(PagoAplicacion::query()->whereNull('venta_id')->sole()->monto)->toBe('30.00');
    }
    expect($stock->refresh()->cantidad_reservada)->toBe('0.0000');
})->with([
    'entrega' => [true, 'entrega'],
    'vencimiento' => [false, 'servicio'],
    'consulta procesa el vencimiento' => [false, 'consulta'],
    'venta fallida conserva el vencimiento' => [false, 'venta fallida'],
    'entrega rechazada conserva el vencimiento' => [false, 'entrega vencida'],
]);

it('amplía una reserva vigente con permiso individual y conserva el historial', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('ampliar-reserva');
    cargarStock($variante, $almacen, $cajero, $ocasional, '3');
    $cliente = Cliente::factory()->personaNatural()->create();
    $caja = app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $reserva = app(CompletarVentaContado::class)->reservar([
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'cliente_id' => $cliente->id,
        'vence_en' => now()->addDay()->toDateTimeString(),
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '30']],
    ], $cajero)['venta'];
    $actual = $reserva->vence_en->format('Y-m-d H:i:s');
    $nuevo = $reserva->vence_en->copy()->addDay()->startOfMinute()->format('Y-m-d H:i:s');
    $reservas = app(GestionarReservas::class);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    $accion = [TestAction::make('verDetalle')->table($reserva), TestAction::make('ampliarReserva')];
    $tabla = fn () => Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $caja->id]);
    $tabla()->assertActionHidden($accion);
    expect(fn () => $reservas->ampliar($reserva, $nuevo, $actual, 'Solicitud', $cajero))->toThrow(HttpException::class);
    $cajero->givePermissionTo('Ampliar:Reserva');
    $tabla()->mountAction($accion)->assertSchemaStateSet([
        'vencimiento' => $reserva->vence_en->copy()->addDay()->format('Y-m-d').' 23:59',
    ]);
    expect(fn () => $reservas->ampliar($reserva, $actual, $actual, 'Solicitud', $cajero))->toThrow(ValidationException::class);
    expect(fn () => $reservas->ampliar($reserva, $nuevo, $actual, ' ', $cajero))->toThrow(ValidationException::class);

    $tabla()->callAction($accion, data: ['vencimiento' => $nuevo, 'motivo' => 'Cliente solicita más tiempo'])->assertNotified('Reserva ampliada');

    expect($reserva->refresh()->vence_en->format('Y-m-d H:i:s'))->toBe($nuevo);
    expect(fn () => $reservas->ampliar($reserva, now()->addDays(5)->toDateTimeString(), $actual, 'Reintento', $cajero))->toThrow(ValidationException::class);
    $registro = DB::table('ampliaciones_reserva')->sole();
    expect($registro->user_id)->toBe($cajero->id);
    expect($registro->motivo)->toBe('Cliente solicita más tiempo');
    expect(InventarioStock::query()->sole()->cantidad_reservada)->toBe('1.0000');
    expect(Pago::query()->count())->toBe(1);
    $detalle = $tabla()->mountAction(TestAction::make('verDetalle')->table($reserva));
    expect($detalle->instance()->getMountedAction()->getModalContent()->render())
        ->toContain('Lo que pasó', 'Reserva ampliada', 'Cliente solicita más tiempo');
    $this->travelTo($reserva->vence_en->copy()->addSecond());
    expect(fn () => $reservas->ampliar($reserva, now()->addDay()->toDateTimeString(), $nuevo, 'Tarde', $cajero))->toThrow(ValidationException::class);
    expect($reserva->refresh()->estado)->toBe(EstadoVenta::Vencida);
    expect(InventarioStock::query()->sole()->cantidad_reservada)->toBe('0.0000');
});

it('cancela una reserva con motivo y libera stock y seña una sola vez sin mover caja', function (bool $reembolsable) {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('cancelar-reserva');
    cargarStock($variante, $almacen, $cajero, $ocasional, '3');
    $cliente = Cliente::factory()->personaNatural()->create();
    NegocioConfiguracion::query()->first()->update(['permite_reembolso_senas' => $reembolsable]);
    $caja = app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $datos = [
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'cliente_id' => $cliente->id,
        'vence_en' => now()->addDay()->toDateTimeString(),
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '30']],
    ];
    $ventas = app(CompletarVentaContado::class);
    $reserva = $ventas->reservar($datos, $cajero)['venta'];
    $otraReserva = $ventas->reservar($datos, $cajero)['venta'];
    $reservas = app(GestionarReservas::class);
    $saldo = app(SaldoAFavorCliente::class);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    $cancelar = [TestAction::make('verDetalle')->table($reserva), TestAction::make('cancelarReserva')];
    $tabla = fn () => Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $caja->id]);
    $tabla()->assertActionHidden($cancelar);
    expect(fn () => $reservas->cancelar($reserva, 'El cliente desiste', $cajero))->toThrow(HttpException::class);
    $cajero->givePermissionTo('Update:Venta');
    expect(fn () => $reservas->cancelar($reserva, '   ', $cajero))->toThrow(ValidationException::class);
    $pago = $reserva->aplicacionesPago()->firstOrFail()->pago;
    $pago->update(['estado_pago' => EstadoPago::Pendiente]);
    expect(fn () => $reservas->cancelar($reserva, 'Pago pendiente', $cajero))->toThrow(ValidationException::class);
    $pago->update(['estado_pago' => EstadoPago::Aprobado]);
    expect($reserva->refresh()->estado)->toBe(EstadoVenta::Reserva);
    expect($saldo->disponible($cliente->id))->toBe('0.00');
    $caja->update(['estado_sesion' => EstadoCajaSesion::Cerrada]);

    $tabla()->callAction($cancelar, data: ['motivo' => 'El cliente desiste'])->assertNotified('Reserva cancelada');

    expect($reserva->refresh()->estado)->toBe(EstadoVenta::Anulada);
    expect($reserva->anulada_por_id)->toBe($cajero->id);
    expect($reserva->anulada_en)->not->toBeNull();
    expect($reserva->motivo_anulacion)->toBe('El cliente desiste');
    expect($reserva->saldoPendiente())->toBe('0.00');
    expect($otraReserva->refresh()->estado)->toBe(EstadoVenta::Reserva);
    $stock = InventarioStock::query()->where('producto_variante_id', $variante->id)->sole();
    expect($stock->cantidad_actual)->toBe('3.0000');
    expect($stock->cantidad_reservada)->toBe('1.0000');
    expect($saldo->disponible($cliente->id))->toBe('30.00');
    expect(Pago::query()->count())->toBe(2);
    expect(app(ArqueoCajaSesion::class)->de($caja)['total_cobros'])->toBe('60.00');
    expect(MovimientoInventario::query()->whereIn('tipo_movimiento', [TipoMovimientoInventario::SalidaVenta, TipoMovimientoInventario::Devolucion])->count())->toBe(0);
    expect(fn () => $reservas->cancelar($reserva, 'Reintento', $cajero))->toThrow(ValidationException::class);
    expect(fn () => $reservas->entregar($reserva, '70.00', $cajero))->toThrow(ValidationException::class);
    $reservas->vencer($reserva);
    expect($saldo->disponible($cliente->id))->toBe('30.00');
    expect($stock->refresh()->cantidad_reservada)->toBe('1.0000');
    $detalle = $tabla()->assertCanSeeTableRecords([$reserva])->assertActionHidden($cancelar)
        ->mountAction(TestAction::make('verDetalle')->table($reserva));
    expect($detalle->instance()->getMountedAction()->getModalContent()->render())->toContain('Reserva cancelada.');

    expect(fn () => $saldo->reembolsar($reserva, '30', 'Solicitud del cliente', $supervisor, '30.00'))->toThrow(ValidationException::class);
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $supervisor);
    if ($reembolsable) {
        $saldo->reembolsar($reserva, '30', 'Solicitud del cliente', $supervisor, '30.00');
        expect($saldo->disponible($cliente->id))->toBe('0.00');
        expect(Pago::query()->where('sentido', SentidoPago::Reembolso)->sole()->monto)->toBe('30.00');
    } else {
        expect(fn () => $saldo->reembolsar($reserva, '30', 'Solicitud del cliente', $supervisor, '30.00'))->toThrow(ValidationException::class);
        expect($saldo->disponible($cliente->id))->toBe('30.00');
        expect(Pago::query()->where('sentido', SentidoPago::Reembolso)->count())->toBe(0);
    }
    $tenantOriginal = tenant();
    tenancy()->initialize(Tenant::factory()->create());
    $otroUsuario = TenantUser::factory()->create();
    $otroUsuario->assignRole('supervisor');
    expect(fn () => $reservas->cancelar($reserva, 'Otro negocio', $otroUsuario))->toThrow(ModelNotFoundException::class);
    tenancy()->initialize($tenantOriginal);
})->with(['seña reembolsable' => true, 'seña para otra compra' => false]);

it('rechaza cancelar una reserva entregada o vencida sin repetir la liberación', function (bool $entregada) {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('cancelar-finalizada');
    cargarStock($variante, $almacen, $cajero, $ocasional, '3');
    $cliente = Cliente::factory()->personaNatural()->create();
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $reserva = app(CompletarVentaContado::class)->reservar([
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'cliente_id' => $cliente->id,
        'vence_en' => now()->addHour()->toDateTimeString(),
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '30']],
    ], $cajero)['venta'];
    $reservas = app(GestionarReservas::class);
    if ($entregada) {
        $reservas->entregar($reserva, '70.00', $cajero);
    } else {
        $this->travelTo($reserva->vence_en->copy()->addSecond());
    }

    expect(fn () => $reservas->cancelar($reserva, 'Solicitud tardía', $supervisor))->toThrow(ValidationException::class);

    expect($reserva->refresh()->estado)->toBe($entregada ? EstadoVenta::Completada : EstadoVenta::Vencida);
    expect($reserva->anulada_en)->toBeNull();
    expect(InventarioStock::query()->sole()->cantidad_reservada)->toBe('0.0000');
    expect(InventarioStock::query()->sole()->cantidad_actual)->toBe($entregada ? '2.0000' : '3.0000');
    expect(app(SaldoAFavorCliente::class)->disponible($cliente->id))->toBe($entregada ? '0.00' : '30.00');
})->with(['entregada' => true, 'vencida' => false]);

it('aplica saldo de una seña y controla el reembolso restante con su política original', function (bool $reembolsable, bool $soloSaldo) {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('saldo-favor');
    cargarStock($variante, $almacen, $cajero, $ocasional);
    $cliente = Cliente::factory()->personaNatural()->create();
    NegocioConfiguracion::query()->first()->update(['permite_reembolso_senas' => $reembolsable]);
    $abrir = app(AbrirCajaSesion::class);
    $primera = $abrir->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $reserva = app(CompletarVentaContado::class)->reservar([
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'cliente_id' => $cliente->id,
        'vence_en' => now()->addDay()->toDateTimeString(),
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '30']],
    ], $cajero)['venta'];
    NegocioConfiguracion::query()->first()->update(['permite_reembolso_senas' => ! $reembolsable]);
    $this->travel(2)->days();
    $segunda = $abrir->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    app(GestionarReservas::class)->procesarVencidas();
    $saldo = app(SaldoAFavorCliente::class);
    expect($saldo->disponible($cliente->id))->toBe('30.00');
    $otroCliente = Cliente::factory()->personaNatural()->create();
    foreach ([[$cliente->id, '31', '69'], [$otroCliente->id, '20', '80']] as [$clienteId, $aplicar, $efectivo]) {
        expect(fn () => app(CompletarVentaContado::class)->completar([
            'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'cliente_id' => $clienteId,
            'saldo_favor' => $aplicar,
            'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
            'pagos' => [['metodo_pago' => 'efectivo', 'monto' => $efectivo]],
        ], $cajero))->toThrow(ValidationException::class);
    }
    expect(Venta::query()->count())->toBe(1)
        ->and(Pago::query()->count())->toBe(1)
        ->and($saldo->disponible($cliente->id))->toBe('30.00');
    if ($soloSaldo) {
        $variante->update(['precio_venta' => '20.00']);
    }
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')->test(MostradorDelNegocio::class)
        ->call('seleccionarResultado', $variante->id)->call('seleccionarCliente', $cliente->id)
        ->set('saldoFavor', '20')->set('pagos.0.monto', $soloSaldo ? '' : '80')->call('completar')->assertHasNoErrors();

    $venta = Venta::query()->whereKeyNot($reserva->id)->sole();
    expect($venta->saldoPendiente())->toBe('0.00')
        ->and($venta->aplicacionesPago()->where('es_saldo_favor', true)->sole()->monto)->toBe('20.00')
        ->and($saldo->disponible($cliente->id))->toBe('10.00')
        ->and(app(ArqueoCajaSesion::class)->de($primera)['total_cobros'])->toBe('30.00')
        ->and(app(ArqueoCajaSesion::class)->de($segunda)['total_cobros'])->toBe($soloSaldo ? '0.00' : '80.00');
    Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $primera->id])
        ->assertCanNotSeeTableRecords([$venta]);

    expect(fn () => $saldo->reembolsar($reserva, '5', 'Solicitud del cliente', $cajero, '10.00'))->toThrow(HttpException::class);
    $cajaSupervisor = $abrir->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '20'], $supervisor);
    if ($reembolsable) {
        Livewire::actingAs($supervisor, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $primera->id, 'supervision' => true])
            ->callAction([TestAction::make('verDetalle')->table($reserva), TestAction::make('reembolsarSena')], data: [
                'monto' => '5', 'motivo' => 'Solicitud del cliente',
            ])->assertNotified('Reembolso registrado');
        expect(fn () => $saldo->reembolsar($reserva, '5', 'Reintento', $supervisor, '10.00'))->toThrow(ValidationException::class);
        expect($saldo->disponible($cliente->id))->toBe('5.00')
            ->and(app(ArqueoCajaSesion::class)->de($cajaSupervisor)['total_reembolsos'])->toBe('5.00');
        $reembolso = Pago::query()->where('sentido', SentidoPago::Reembolso)->sole();
        expect($reembolso->registrado_por_id)->toBe($supervisor->id)->and($reembolso->motivo)->toBe('Solicitud del cliente');
    } else {
        expect(fn () => $saldo->reembolsar($reserva, '5', 'Solicitud del cliente', $supervisor, '10.00'))->toThrow(ValidationException::class);
        expect($saldo->disponible($cliente->id))->toBe('10.00')
            ->and(Pago::query()->where('sentido', SentidoPago::Reembolso)->count())->toBe(0);
    }
    expect(fn () => $saldo->aplicar($venta, '20'))->toThrow(ValidationException::class);
    if ($soloSaldo) {
        $reemplazo = ProductoVariante::factory()->create(['precio_venta' => '10.00']);
        cargarStock($reemplazo, $almacen, $cajero, $ocasional, '3');
        $lineas = [['origen' => 0, 'producto_variante_id' => $reemplazo->id, 'cantidad' => '1']];
        $corregir = app(CorregirVenta::class);
        $revision = $corregir->revision($venta, $corregir->revisar($venta, $lineas));
        $corregir->confirmar($venta, $lineas, 'Corrección cubierta con saldo', $revision, $supervisor);
        $venta->refresh();
        expect($venta->cobradoNeto())->toBe('10.00')
            ->and($saldo->disponible($cliente->id))->toBe('15.00')
            ->and(app(ArqueoCajaSesion::class)->de($cajaSupervisor)['total_reembolsos'])->toBe('5.00');
    }
    app(AnularVenta::class)->anular($venta, 'Cancelación de compra con saldo', $venta->cobradoNeto(), $supervisor);
    expect($saldo->disponible($cliente->id))->toBe($reembolsable ? '25.00' : '30.00')
        ->and($saldo->disponibleDeReserva($reserva))->toBe($reembolsable ? '25.00' : '30.00')
        ->and(app(ArqueoCajaSesion::class)->de($cajaSupervisor)['total_reembolsos'])
        ->toBe($soloSaldo ? '5.00' : ($reembolsable ? '85.00' : '80.00'));
    $tenantOriginal = tenant();
    tenancy()->initialize(Tenant::factory()->create());
    expect($saldo->disponible($cliente->id))->toBe('0.00');
    tenancy()->initialize($tenantOriginal);
})->with([
    'permite reembolso' => [true, false],
    'solo permite otra compra' => [false, false],
    'compra cubierta con saldo' => [true, true],
]);

it('permite preparar una venta sin caja vigente y conserva el carrito al intentar confirmarla', function (bool $cajaAnterior) {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('navegar-sin-caja');
    cargarStock($variante, $almacen, $cajero, $ocasional, '3');
    if ($cajaAnterior) {
        $this->travel(-1)->days();
        app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
        $this->travelBack();
    }
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $mostrador = Livewire::actingAs($cajero, 'tenant')->test(MostradorDelNegocio::class)
        ->assertNoRedirect()->assertSee('Abrí la caja de hoy')
        ->call('seleccionarResultado', $variante->id)
        ->set('pagos.0.monto', '100')
        ->call('completar')->assertNotified('Abrí la caja de hoy');

    expect($mostrador->get('lineas'))->toHaveCount(1);
    expect(Venta::query()->count())->toBe(0);
    expect(InventarioStock::query()->sole()->cantidad_actual)->toBe('3.0000');
    app(CongelarTurnosVencidos::class)->de($cajero);
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $mostrador->call('$refresh')->assertSee('Caja abierta')->call('completar')->assertHasNoErrors()
        ->assertSet('ultimoVuelto', '');
    expect(Venta::query()->count())->toBe(1);
})->with(['sin sesión' => false, 'sesión anterior abierta' => true]);

it('pide revisar el carrito si se publicó un precio nuevo mientras estaba abierto', function () {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('revisar-precio');
    cargarStock($variante, $almacen, $cajero, $ocasional, '3');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    NegocioConfiguracion::query()->firstOrFail()->update(['modo_moneda' => ModoMoneda::ConDolar]);
    $variante->update(['precio_venta_dolar' => '10']);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    $mostrador = Livewire::actingAs($cajero, 'tenant')->test(MostradorDelNegocio::class)
        ->call('seleccionarResultado', $variante->id)
        ->set('pagos.0.monto', '100');

    $publicacion = app(PublicarTipoCambio::class);
    $publicacion->publicar('12', $publicacion->previsualizar('12')['firma'], $supervisor);

    $mostrador->call('completar')->assertHasErrors(['precios_carrito'])->assertSet('lineas.0.precio_unitario', '120.00');
    expect(Venta::query()->count())->toBe(0);

    $mostrador->set('pagos.0.monto', '120')->call('completar')->assertHasNoErrors();
    expect(Venta::query()->sole()->total)->toBe('120.00');
});

it('registra el efectivo recibido y el vuelto sin aumentar cobros ni reembolsos', function (string $operacion, string $aplicado, bool $combinado) {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('vuelto');
    cargarStock($variante, $almacen, $cajero, $ocasional, '3');
    $cliente = Cliente::factory()->personaNatural()->create();
    $caja = app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    $mostrador = Livewire::actingAs($cajero, 'tenant')->test(MostradorDelNegocio::class)
        ->call('seleccionarResultado', $variante->id)->call('seleccionarCliente', $cliente->id)
        ->call('seleccionarOperacion', $operacion)->set('pagos.0.monto', $aplicado)->set('pagos.0.efectivo_recibido', '200');
    if ($combinado) {
        $mostrador->call('agregarPago')->set('pagos.1.metodo_pago', 'qr_externo')->set('pagos.1.monto', '30');
    }
    $mostrador->assertSee('Cambio a entregar')->call('completar')->assertHasNoErrors();
    $venta = Venta::query()->sole();
    $pago = Pago::query()->where('metodo_pago', MetodoPago::Efectivo)->sole();
    expect($pago->monto)->toBe($aplicado);
    expect($pago->efectivo_recibido)->toBe('200.00');
    expect($pago->cambio_entregado)->toBe($combinado ? '130.00' : ($operacion === 'contado' ? '100.00' : '170.00'));
    expect(app(ArqueoCajaSesion::class)->de($caja)['total_cobros'])->toBe($operacion === 'contado' ? '100.00' : '30.00');
    $mostrador->assertNotified('Venta '.$venta->codigo.' registrada')
        ->assertSee('Vuelto')
        ->assertSet('ultimoVuelto', $pago->cambio_entregado);
    if ($operacion === 'contado' && ! $combinado) {
        $mostrador->mountAction('detalleUltimaVenta')->assertActionMounted('detalleUltimaVenta');
        expect($mostrador->instance()->getMountedAction()->getModalContent()->render())
            ->toContain('Lo que pasó', 'Venta registrada', 'Cambio:');
        $mostrador->unmountAction()
            ->call('seleccionarResultado', $variante->id)
            ->assertSet('ultimoVuelto', '')
            ->assertSee('Ver detalle')
            ->assertSee($venta->codigo);
    }
    $detalle = Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $caja->id])
        ->mountAction(TestAction::make('verDetalle')->table($venta));
    expect($detalle->instance()->getMountedAction()->getModalContent()->render())->toContain('Recibido:', 'Cambio:', '200.00');
    if ($combinado) {
        expect(Pago::query()->where('metodo_pago', MetodoPago::QrExterno)->sole()->efectivo_recibido)->toBeNull();
    }
    if ($operacion === 'reserva') {
        Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $caja->id])
            ->callAction([TestAction::make('verDetalle')->table($venta), TestAction::make('entregarReserva')], data: ['efectivo_recibido' => '100'])
            ->assertNotified('Reserva entregada');
    } elseif ($operacion === 'credito') {
        Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sucursalMostradorId' => $sucursal->id])
            ->callAction([TestAction::make('verDetalle')->table($venta), TestAction::make('registrarAbono')], data: ['monto' => '70', 'efectivo_recibido' => '100'])
            ->assertNotified('Abono registrado');
    }
    expect($venta->refresh()->saldoPendiente())->toBe('0.00');
    expect(app(ArqueoCajaSesion::class)->de($caja)['total_cobros'])->toBe('100.00');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $supervisor);
    app(AnularVenta::class)->anular($venta, 'Comprobar reembolso del cobro aplicado', '100.00', $supervisor);
    expect(Pago::query()->where('sentido', SentidoPago::Reembolso)->sum('monto'))->toEqual(100);
    if ($operacion === 'contado' && ! $combinado) {
        $cajero->syncRoles([]);
        $cajero->givePermissionTo('Create:Venta');
        $mostrador->call('$refresh')->assertDontSee('Ver detalle')
            ->mountAction('detalleUltimaVenta')->assertActionNotMounted();
    }
})->with([
    'contado' => ['contado', '100.00', false],
    'QR y efectivo' => ['contado', '70.00', true],
    'seña y entrega' => ['reserva', '30.00', false],
    'crédito y abono' => ['credito', '30.00', false],
]);

it('rechaza efectivo insuficiente o inválido y vuelto en métodos electrónicos', function (string $metodo, string $recibido) {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('vuelto-invalido');
    cargarStock($variante, $almacen, $cajero, $ocasional, '3');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    expect(fn () => app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => $metodo, 'monto' => '100', 'efectivo_recibido' => $recibido, 'cambio_entregado' => '999']],
    ], $cajero))->toThrow(ValidationException::class);
    expect(Venta::query()->count())->toBe(0);
    expect(Pago::query()->count())->toBe(0);
    expect(InventarioStock::query()->sole()->cantidad_actual)->toBe('3.0000');
})->with([
    'insuficiente' => ['efectivo', '99'],
    'negativo' => ['efectivo', '-1'],
    'demasiados decimales' => ['efectivo', '100.001'],
    'QR' => ['qr_externo', '200'],
    'transferencia' => ['transferencia', '200'],
    'tarjeta' => ['voucher_tarjeta', '200'],
]);

it('congela la política de devolución y registra la entrega sin cambiarla por abonos o retiro', function (string $operacion) {
    $this->travelTo(Carbon::parse('2026-11-10 10:00:00'));
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('politica-venta');
    cargarStock($variante, $almacen, $cajero, $ocasional, '3');
    $cliente = Cliente::factory()->personaNatural()->create();
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $configuracion = NegocioConfiguracion::query()->firstOrFail();
    $configuracion->update(['acepta_devoluciones' => true, 'dias_devolucion' => 7, 'devolucion_permite_reembolso' => true]);
    $datos = [
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'cliente_id' => $cliente->id,
        'vence_en' => now()->addDays(2)->toDateTimeString(),
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => $operacion === 'contado' ? '100' : '30']],
    ];
    $ventas = app(CompletarVentaContado::class);
    $resultado = match ($operacion) {
        'reserva' => $ventas->reservar($datos, $cajero),
        'credito' => $ventas->venderFiado($datos, $cajero),
        default => $ventas->completar($datos, $cajero),
    };
    $venta = $resultado['venta'];
    expect($venta->politica_devolucion_snapshot['version'])->toBe(1);
    expect($venta->politica_devolucion_snapshot['acepta_devoluciones'])->toBeTrue();
    expect($venta->politica_devolucion_snapshot['dias_devolucion'])->toBe(7);
    expect($venta->entregada_en?->format('Y-m-d H:i:s'))->toBe($operacion === 'reserva' ? null : '2026-11-10 10:00:00');
    $configuracion->update(['acepta_devoluciones' => false, 'dias_devolucion' => 1, 'devolucion_permite_reembolso' => false]);
    $this->travel(1)->hour();
    if ($operacion === 'reserva') {
        app(GestionarReservas::class)->entregar($venta, '70.00', $cajero);
    } elseif ($operacion === 'credito') {
        $ventas->abonarEnEfectivo($venta->codigo, $sucursal->id, '70', $cajero);
    }
    $venta->refresh();
    expect($venta->politica_devolucion_snapshot['acepta_devoluciones'])->toBeTrue();
    expect($venta->politica_devolucion_snapshot['dias_devolucion'])->toBe(7);
    expect($venta->politica_devolucion_snapshot['permite_reembolso'])->toBeTrue();
    expect($venta->entregada_en->format('Y-m-d H:i:s'))->toBe($operacion === 'reserva' ? '2026-11-10 11:00:00' : '2026-11-10 10:00:00');
    $nueva = $ventas->completar([...$datos, 'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '100']]], $cajero)['venta'];
    expect($nueva->politica_devolucion_snapshot['acepta_devoluciones'])->toBeFalse();
    expect($nueva->politica_devolucion_snapshot['dias_devolucion'])->toBe(1);
})->with(['contado', 'credito', 'reserva']);

it('registra una devolución parcial desde el detalle y conserva los cobros de la sesión original', function () {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('devolucion-parcial');
    cargarStock($variante, $almacen, $cajero, $ocasional, '5');
    NegocioConfiguracion::query()->first()->update(['acepta_devoluciones' => true, 'dias_devolucion' => 7, 'devolucion_permite_reembolso' => true]);
    $cajaOriginal = app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $venta = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '2']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '200', 'efectivo_recibido' => '300']],
    ], $cajero)['venta'];
    $variante->update(['precio_venta' => '999']);
    $this->travel(1)->days();
    $caja = app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '100'], $supervisor);
    $servicio = app(RegistrarDevolucion::class);
    $lineas = [['indice' => 0, 'cantidad' => '1']];
    expect(fn () => $servicio->revisar($venta, $lineas, 'reembolso', '', $cajero))->toThrow(HttpException::class);
    $revision = $servicio->revisar($venta, $lineas, 'reembolso', '', $supervisor)['revision'];
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    Livewire::actingAs($supervisor, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $cajaOriginal->id, 'supervision' => true])
        ->callAction([TestAction::make('verDetalle')->table($venta), TestAction::make('devolverVenta')], data: [
            'lineas' => $lineas, 'motivo' => 'Medida incorrecta', 'resolucion' => 'reembolso', 'revision' => $revision, 'confirmado' => true,
        ])->assertNotified('Devolución registrada');
    expect($venta->refresh()->totalVigente())->toBe('100.00');
    expect($venta->cobradoNeto())->toBe('100.00');
    expect($venta->saldoPendiente())->toBe('0.00');
    expect(InventarioStock::query()->sole()->cantidad_actual)->toBe('4.0000');
    expect(MovimientoInventario::query()->where('tipo_movimiento', TipoMovimientoInventario::Devolucion)->sole()->costo_unitario)->toBe('40.0000');
    expect(app(ArqueoCajaSesion::class)->de($cajaOriginal)['total_cobros'])->toBe('200.00');
    expect(app(ArqueoCajaSesion::class)->de($caja)['total_reembolsos'])->toBe('100.00');
    expect(fn () => $servicio->confirmar($venta, $lineas, 'reembolso', 'Reintento', '', $revision, $supervisor))->toThrow(ValidationException::class);
    expect(fn () => $servicio->revisar($venta, [['indice' => 0, 'cantidad' => '2']], 'reembolso', '', $supervisor))->toThrow(ValidationException::class);
    expect(fn () => app(AnularVenta::class)->anular($venta, 'Anular', '100.00', $supervisor))->toThrow(ValidationException::class);
    expect(fn () => app(CorregirVenta::class)->revisar($venta, []))->toThrow(ValidationException::class);
    $detalle = Livewire::actingAs($supervisor, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $cajaOriginal->id, 'supervision' => true])
        ->mountAction(TestAction::make('verDetalle')->table($venta));
    $html = $detalle->instance()->getMountedAction()->getModalContent()->render();
    expect($html)->toContain('Productos después de las devoluciones', 'Lo que pasó', 'Devolución registrada', 'Reembolso de BOB 100.00', 'Medida incorrecta', 'Venta registrada');
    expect(strpos($html, 'Devolución registrada'))->toBeLessThan(strpos($html, 'Venta registrada'));
});

it('copia la exclusión comercial y deja que el producto reabra la categoría', function () {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('exclusion-devolucion');
    cargarStock($variante, $almacen, $cajero, $ocasional, '5');
    NegocioConfiguracion::query()->first()->update(['acepta_devoluciones' => true, 'dias_devolucion' => 7, 'devolucion_permite_reembolso' => true]);
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $supervisor);
    $categoria = $variante->productoMaestro->categoria;
    $categoria->update(['nombre' => 'Lubricantes', 'sin_devolucion_comercial' => true]);
    $ventas = app(CompletarVentaContado::class);
    $datos = [
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '100']],
    ];
    $bloqueada = $ventas->completar($datos, $cajero)['venta'];
    $linea = [['indice' => 0, 'cantidad' => '1']];
    $devolver = app(RegistrarDevolucion::class);

    expect(VentaDetalle::query()->where('venta_id', $bloqueada->id)->first())
        ->sin_devolucion_comercial->toBeTrue()
        ->motivo_sin_devolucion->toBe('Lubricantes');
    expect($devolver->lineasParaFormulario($bloqueada)[0]['bloqueada'])->toBeTrue();
    expect(fn () => $devolver->revisar($bloqueada, $linea, 'reembolso', '', $supervisor))->toThrow(ValidationException::class);
    expect(fn () => $devolver->revisarCambio($bloqueada, $linea, '', $supervisor))->toThrow(ValidationException::class);

    $variante->productoMaestro->update(['devolucion_comercial' => DevolucionComercialProducto::Permite]);
    $reabierta = $ventas->completar($datos, $cajero)['venta'];

    expect(VentaDetalle::query()->where('venta_id', $reabierta->id)->first()->sin_devolucion_comercial)->toBeFalse();
    expect($devolver->revisar($reabierta, $linea, 'reembolso', '', $supervisor))->toHaveKey('revision');
    expect(fn () => $devolver->revisar($bloqueada->fresh(), $linea, 'reembolso', '', $supervisor))->toThrow(ValidationException::class);

    $categoria->update(['sin_devolucion_comercial' => false]);
    $variante->productoMaestro->update(['nombre' => 'Filtro especial', 'devolucion_comercial' => DevolucionComercialProducto::Bloquea]);
    $producto = $ventas->completar($datos, $cajero)['venta'];

    expect(VentaDetalle::query()->where('venta_id', $producto->id)->first()->motivo_sin_devolucion)->toBe('Filtro especial');
    expect(fn () => $devolver->revisar($producto, $linea, 'reembolso', '', $supervisor))->toThrow(ValidationException::class);
});

it('resuelve devoluciones como saldo a favor o reducción de deuda sin duplicar dinero', function (bool $credito) {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('devolucion-saldo');
    cargarStock($variante, $almacen, $cajero, $ocasional, '5');
    $cliente = Cliente::factory()->personaNatural()->create();
    NegocioConfiguracion::query()->first()->update(['acepta_devoluciones' => true, 'devolucion_permite_saldo_favor' => true]);
    $caja = app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $datos = [
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'cliente_id' => $cliente->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => $credito ? '30' : '100']],
    ];
    $ventas = app(CompletarVentaContado::class);
    $venta = ($credito ? $ventas->venderFiado($datos, $cajero) : $ventas->completar($datos, $cajero))['venta'];
    NegocioConfiguracion::query()->first()->update(['acepta_devoluciones' => false]);
    $devolver = app(RegistrarDevolucion::class);
    $lineas = [['indice' => 0, 'cantidad' => '1']];
    $resumen = $devolver->revisar($venta, $lineas, 'saldo_favor', '', $supervisor);
    expect($resumen['deuda'])->toBe($credito ? '70.00' : '0.00');
    $devolver->confirmar($venta, $lineas, 'saldo_favor', 'Cliente cambia de decisión', '', $resumen['revision'], $supervisor);
    expect($venta->refresh()->totalVigente())->toBe('0.00');
    expect($venta->saldoPendiente())->toBe('0.00');
    expect(app(SaldoAFavorCliente::class)->disponible($cliente->id))->toBe($credito ? '30.00' : '100.00');
    expect(Pago::query()->count())->toBe(1);
    expect(app(ArqueoCajaSesion::class)->de($caja)['total_cobros'])->toBe($credito ? '30.00' : '100.00');
    $nueva = $ventas->completar([...$datos, 'saldo_favor' => $credito ? '30' : '100', 'pagos' => $credito ? [['metodo_pago' => 'efectivo', 'monto' => '70']] : []], $cajero)['venta'];
    expect($nueva->saldoPendiente())->toBe('0.00');
    expect(app(SaldoAFavorCliente::class)->disponible($cliente->id))->toBe('0.00');
})->with(['contado' => false, 'crédito' => true]);

it('controla el plazo de devolución y permite una excepción autorizada con motivo', function () {
    $this->travelTo(Carbon::parse('2026-11-10 10:00:00'));
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('devolucion-plazo');
    cargarStock($variante, $almacen, $cajero, $ocasional);
    NegocioConfiguracion::query()->first()->update(['acepta_devoluciones' => true, 'devolucion_permite_reembolso' => true, 'devolucion_permite_excepcion' => true]);
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $venta = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '100']],
    ], $cajero)['venta'];
    $this->travelTo(Carbon::parse('2026-11-11 00:00:00'));
    $cajero->givePermissionTo('Registrar:Devolucion');
    $devolver = app(RegistrarDevolucion::class);
    $lineas = [['indice' => 0, 'cantidad' => '1']];
    expect(fn () => $devolver->revisar($venta, $lineas, 'reembolso', 'Un día tarde', $cajero))->toThrow(ValidationException::class);
    expect(fn () => $devolver->revisar($venta, $lineas, 'reembolso', '', $supervisor))->toThrow(ValidationException::class);
    expect(fn () => $devolver->revisar($venta, $lineas, 'reembolso', 'Un día tarde', $supervisor))->toThrow(ValidationException::class);
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '100'], $supervisor);
    $resumen = $devolver->revisar($venta, $lineas, 'reembolso', 'Cliente autorizado un día tarde', $supervisor);
    $devolver->confirmar($venta, $lineas, 'reembolso', 'No le sirve', 'Cliente autorizado un día tarde', $resumen['revision'], $supervisor);
    expect($venta->devoluciones()->sole()->motivo_excepcion)->toBe('Cliente autorizado un día tarde');
    expect($venta->refresh()->saldoPendiente())->toBe('0.00');
    $original = tenant();
    tenancy()->initialize(Tenant::factory()->create());
    $otro = TenantUser::factory()->create();
    $otro->assignRole('supervisor');
    expect(fn () => $devolver->revisar($venta, $lineas, 'reembolso', '', $otro))->toThrow(ModelNotFoundException::class);
    tenancy()->initialize($original);
});

it('distribuye descuentos y centavos en devoluciones sucesivas sin exceder la venta', function () {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('devolucion-redondeo');
    cargarStock($variante, $almacen, $cajero, $ocasional);
    $otra = ProductoVariante::factory()->create(['precio_venta' => '10.00']);
    cargarStock($otra, $almacen, $cajero, $ocasional, '1');
    NegocioConfiguracion::query()->first()->update(['acepta_devoluciones' => true, 'devolucion_permite_reembolso' => true]);
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '300'], $supervisor);
    $venta = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id,
        'tipo_descuento' => 'importe', 'valor_descuento' => '1', 'supervisor_descuento_id' => $supervisor->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '3'], ['producto_variante_id' => $otra->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '309']],
    ], $cajero)['venta'];
    $servicio = app(RegistrarDevolucion::class);
    foreach (['99.68', '99.67', '99.68'] as $esperado) {
        $lineas = [['indice' => 0, 'cantidad' => '1']];
        $resumen = $servicio->revisar($venta, $lineas, 'reembolso', '', $supervisor);
        expect($resumen['total'])->toBe($esperado);
        $servicio->confirmar($venta, $lineas, 'reembolso', 'Devolución por partes', '', $resumen['revision'], $supervisor);
    }
    expect($venta->refresh()->totalVigente())->toBe('9.97');
    $restante = [['indice' => 1, 'cantidad' => '1']];
    $resumen = $servicio->revisar($venta, $restante, 'reembolso', '', $supervisor);
    expect($resumen['total'])->toBe('9.97');
    $servicio->confirmar($venta, $restante, 'reembolso', 'Última pieza devuelta', '', $resumen['revision'], $supervisor);
    expect($venta->refresh()->totalVigente())->toBe('0.00');
    expect($venta->cobradoNeto())->toBe('0.00');
    expect(InventarioStock::query()->where('producto_variante_id', $variante->id)->sole()->cantidad_actual)->toBe('5.0000');
    expect(InventarioStock::query()->where('producto_variante_id', $otra->id)->sole()->cantidad_actual)->toBe('1.0000');
    $reembolsado = Pago::query()->where('sentido', SentidoPago::Reembolso)->get()
        ->reduce(fn (string $total, Pago $pago): string => bcadd($total, $pago->monto, 2), '0.00');
    expect($reembolsado)->toBe('309.00');
    expect(fn () => $servicio->revisar($venta, [['indice' => 0, 'cantidad' => '1']], 'reembolso', '', $supervisor))->toThrow(ValidationException::class);
});

it('respeta las resoluciones guardadas y exige cliente para generar saldo por una devolución', function (bool $acepta, bool $reembolso, string $resolucion) {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('devolucion-condiciones');
    cargarStock($variante, $almacen, $cajero, $ocasional);
    NegocioConfiguracion::query()->first()->update(['acepta_devoluciones' => $acepta, 'devolucion_permite_reembolso' => $reembolso, 'devolucion_permite_saldo_favor' => true]);
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $venta = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '100']],
    ], $cajero)['venta'];
    NegocioConfiguracion::query()->first()->update(['acepta_devoluciones' => true, 'devolucion_permite_reembolso' => true]);
    expect(fn () => app(RegistrarDevolucion::class)->revisar($venta, [['indice' => 0, 'cantidad' => '1']], $resolucion, '', $supervisor))->toThrow(ValidationException::class);
    expect($venta->devoluciones()->count())->toBe(0);
    expect(InventarioStock::query()->sole()->cantidad_actual)->toBe('4.0000');
    expect(Pago::query()->count())->toBe(1);
})->with([
    'no aceptaba devoluciones' => [false, true, 'reembolso'],
    'no permitía reembolso' => [true, false, 'reembolso'],
    'consumidor final no recibe saldo' => [true, true, 'saldo_favor'],
]);

it('devuelve una venta corregida y restituye el saldo usado sin convertirlo en efectivo', function () {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('devolucion-corregida');
    cargarStock($variante, $almacen, $cajero, $ocasional);
    $cliente = Cliente::factory()->personaNatural()->create();
    NegocioConfiguracion::query()->first()->update(['acepta_devoluciones' => true, 'devolucion_permite_reembolso' => true, 'devolucion_permite_saldo_favor' => true]);
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $caja = app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '200'], $supervisor);
    $ventas = app(CompletarVentaContado::class);
    $datos = ['sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'cliente_id' => $cliente->id];
    $venta = $ventas->completar([...$datos,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '2']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '200']],
    ], $cajero)['venta'];
    $corregir = app(CorregirVenta::class);
    $edicion = [['origen' => 0, 'producto_variante_id' => $variante->id, 'cantidad' => '1']];
    $revision = $corregir->revision($venta, $corregir->revisar($venta, $edicion));
    $corregir->confirmar($venta, $edicion, 'Error en la cantidad digitada', $revision, $supervisor);
    $devolver = app(RegistrarDevolucion::class);
    $lineas = [['indice' => 0, 'cantidad' => '1']];
    $resumen = $devolver->revisar($venta, $lineas, 'saldo_favor', '', $supervisor);
    $devolver->confirmar($venta, $lineas, 'saldo_favor', 'Pieza recibida sin uso', '', $resumen['revision'], $supervisor);
    expect($venta->devoluciones()->sole()->detalles()->sole()->venta_detalle_id)->toBeNull();
    expect($venta->refresh()->totalVigente())->toBe('0.00');
    expect($venta->detalles()->sole()->cantidad)->toBe('2.0000');
    expect($venta->cobradoNeto())->toBe('0.00');
    expect(app(SaldoAFavorCliente::class)->disponible($cliente->id))->toBe('100.00');
    $nueva = $ventas->completar([...$datos, 'saldo_favor' => '100',
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']], 'pagos' => [],
    ], $cajero)['venta'];
    $resumen = $devolver->revisar($nueva, $lineas, 'reembolso', '', $supervisor);
    $devolver->confirmar($nueva, $lineas, 'reembolso', 'Restituir el saldo utilizado', '', $resumen['revision'], $supervisor);
    expect($nueva->devoluciones()->sole()->caja_sesion_id)->toBeNull();
    expect($nueva->refresh()->saldoPendiente())->toBe('0.00');
    expect(app(SaldoAFavorCliente::class)->disponible($cliente->id))->toBe('100.00');
    expect(app(ArqueoCajaSesion::class)->de($caja)['total_reembolsos'])->toBe('100.00');
    expect(Pago::query()->count())->toBe(2);
    expect(InventarioStock::query()->sole()->cantidad_actual)->toBe('5.0000');
});

it('reembolsa cada método original y rechaza pagos pendientes sin mover stock ni dinero', function () {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('devolucion-mixta');
    cargarStock($variante, $almacen, $cajero, $ocasional);
    NegocioConfiguracion::query()->first()->update(['acepta_devoluciones' => true, 'devolucion_permite_reembolso' => true]);
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '100'], $supervisor);
    $venta = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => 'qr_externo', 'monto' => '40'], ['metodo_pago' => 'efectivo', 'monto' => '60']],
    ], $cajero)['venta'];
    $servicio = app(RegistrarDevolucion::class);
    $lineas = [['indice' => 0, 'cantidad' => '1']];
    $resumen = $servicio->revisar($venta, $lineas, 'reembolso', '', $supervisor);
    $pago = $venta->aplicacionesPago()->first()->pago;
    $pago->update(['estado_pago' => EstadoPago::Pendiente]);
    expect(fn () => $servicio->confirmar($venta, $lineas, 'reembolso', 'Devolución mixta', '', $resumen['revision'], $supervisor))->toThrow(ValidationException::class);
    expect($venta->devoluciones()->count())->toBe(0);
    expect(InventarioStock::query()->sole()->cantidad_actual)->toBe('4.0000');
    $pago->update(['estado_pago' => EstadoPago::Aprobado]);
    $servicio->confirmar($venta, $lineas, 'reembolso', 'Devolución mixta', '', $resumen['revision'], $supervisor);
    expect(Pago::query()->where('sentido', SentidoPago::Reembolso)->pluck('monto', 'metodo_pago')->all())->toBe(['efectivo' => '60.00', 'qr_externo' => '40.00']);
    expect($venta->refresh()->saldoPendiente())->toBe('0.00');
});

it('repone la cantidad base exacta al devolver por partes una unidad convertida', function () {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('devolucion-unidad');
    $base = UnidadMedida::factory()->create(['nombre' => 'Bidón de tres litros', 'simbolo' => 'bid3', 'uso' => 'cantidad', 'magnitud' => 'volumen', 'factor_a_referencia' => '3', 'decimales' => 4]);
    $litro = UnidadMedida::factory()->create(['nombre' => 'Litro de prueba', 'simbolo' => 'litro', 'uso' => 'cantidad', 'magnitud' => 'volumen', 'factor_a_referencia' => '1', 'decimales' => 4]);
    $variante->productoMaestro->update(['unidad_base_id' => $base->id]);
    cargarStock($variante, $almacen, $cajero, $ocasional);
    NegocioConfiguracion::query()->first()->update(['acepta_devoluciones' => true, 'devolucion_permite_reembolso' => true]);
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '100'], $supervisor);
    $venta = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'unidad_medida_id' => $litro->id, 'cantidad' => '3']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '100']],
    ], $cajero)['venta'];
    expect($venta->detalles()->sole()->cantidad_base)->toBe('1.0000');
    $servicio = app(RegistrarDevolucion::class);
    expect(fn () => $servicio->revisar($venta, [['indice' => 0, 'cantidad' => '0.0001']], 'reembolso', '', $supervisor))->toThrow(ValidationException::class);
    foreach (['0.3333', '0.3334', '0.3333'] as $esperado) {
        $lineas = [['indice' => 0, 'cantidad' => '1']];
        $resumen = $servicio->revisar($venta, $lineas, 'reembolso', '', $supervisor);
        expect($resumen['lineas'][0]['base_devolver'])->toBe($esperado);
        $servicio->confirmar($venta, $lineas, 'reembolso', 'Devolución por litros', '', $resumen['revision'], $supervisor);
    }
    expect(InventarioStock::query()->sole()->cantidad_actual)->toBe('5.0000');
    expect($venta->refresh()->saldoPendiente())->toBe('0.00');
});

/** @return array{venta: Venta, reemplazo: ProductoVariante, cliente: Cliente, supervisor: TenantUser, cajaOriginal: CajaSesion, caja: CajaSesion} */
function prepararCambioDeMostrador(string $precio, bool $reembolso = false, bool $saldoFavor = false): array
{
    test()->travelTo(Carbon::parse('2026-11-10 10:00:00'));
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('cambio-producto');
    NegocioConfiguracion::query()->firstOrFail()->update([
        'acepta_devoluciones' => true, 'dias_devolucion' => 7, 'devolucion_permite_cambio' => true,
        'devolucion_permite_reembolso' => $reembolso, 'devolucion_permite_saldo_favor' => $saldoFavor,
    ]);
    $reemplazo = ProductoVariante::factory()->create(['sku' => 'REPUESTO-CAMBIO', 'precio_venta' => $precio]);
    $cliente = Cliente::factory()->personaNatural()->create();
    cargarStock($variante, $almacen, $cajero, $ocasional, '5');
    cargarStock($reemplazo, $almacen, $cajero, $ocasional, '3');
    $cajaOriginal = app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $venta = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1', 'tipo_descuento' => 'porcentaje', 'valor_descuento' => '5']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '95']],
    ], $cajero)['venta'];
    $variante->update(['precio_venta' => '999']);
    test()->travel(1)->day();
    $caja = app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '50'], $supervisor);

    return compact('venta', 'reemplazo', 'cliente', 'supervisor', 'cajaOriginal', 'caja');
}

it('registra un cambio de contado y refleja solo la diferencia en la caja de hoy', function (
    string $precio, ?string $resolucion, string $cobro, string $reembolso, string $saldo,
) {
    ['venta' => $venta, 'reemplazo' => $reemplazo, 'cliente' => $cliente, 'supervisor' => $supervisor, 'caja' => $caja, 'cajaOriginal' => $cajaOriginal] = prepararCambioDeMostrador($precio, $resolucion === 'reembolso', $resolucion === 'saldo_favor');
    $lineas = [['indice' => 0, 'cantidad' => '1']];
    $entrega = ['producto_variante_id' => $reemplazo->id, 'cantidad' => '1'];
    $cambios = app(CambiarProductoVenta::class);
    $resumen = $cambios->revisar($venta, $lineas, $entrega, '', $supervisor);

    $datos = [
        'lineas' => $lineas, 'reemplazo' => $entrega, 'motivo' => 'Otra medida', 'revision' => $resumen['revision'],
        'resolucion' => $resolucion, 'cliente_saldo_id' => $resolucion === 'saldo_favor' ? $cliente->id : null,
        'metodo_pago' => 'efectivo',
    ];
    $nueva = $cambios->confirmar($venta, $datos, $supervisor);

    expect($venta->refresh()->cliente_id)->toBeNull();
    expect($venta->saldoPendiente())->toBe('0.00');
    expect($venta->cobradoNeto())->toBe('0.00');
    expect($nueva->total)->toBe($precio);
    expect($nueva->saldoPendiente())->toBe('0.00');
    expect($nueva->cambioOrigen->venta_id)->toBe($venta->id);
    expect(InventarioStock::query()->where('producto_variante_id', $venta->detalles()->sole()->producto_variante_id)->sole()->cantidad_actual)->toBe('5.0000');
    expect(InventarioStock::query()->where('producto_variante_id', $reemplazo->id)->sole()->cantidad_actual)->toBe('2.0000');
    $arqueo = app(ArqueoCajaSesion::class);
    expect($arqueo->de($cajaOriginal)['total_cobros'])->toBe('95.00');
    expect($arqueo->de($caja)['total_cobros'])->toBe($cobro);
    expect($arqueo->de($caja)['total_reembolsos'])->toBe($reembolso);
    expect(app(SaldoAFavorCliente::class)->disponible($cliente->id))->toBe($saldo);
    expect(fn () => $cambios->confirmar($venta, $datos, $supervisor))->toThrow(ValidationException::class);
    expect($venta->devoluciones()->count())->toBe(1);
})->with([
    'más caro' => ['110.00', null, '15.00', '0.00', '0.00'],
    'mismo valor sin cliente' => ['95.00', null, '0.00', '0.00', '0.00'],
    'más barato con reembolso' => ['85.00', 'reembolso', '0.00', '10.00', '0.00'],
    'más barato con saldo de consumidor final identificado' => ['85.00', 'saldo_favor', '0.00', '0.00', '10.00'],
]);

it('rechaza un reemplazo más barato si la política solo permite cambios', function () {
    ['venta' => $venta, 'reemplazo' => $reemplazo, 'supervisor' => $supervisor] = prepararCambioDeMostrador('85.00');

    expect(fn () => app(CambiarProductoVenta::class)->revisar($venta, [['indice' => 0, 'cantidad' => '1']], [
        'producto_variante_id' => $reemplazo->id, 'cantidad' => '1',
    ], '', $supervisor))->toThrow(ValidationException::class, 'Elegí un reemplazo de igual o mayor valor.');
    expect($venta->devoluciones()->count())->toBe(0);
});

it('revierte el cambio entero si el cobro de la diferencia es inválido', function () {
    ['venta' => $venta, 'reemplazo' => $reemplazo, 'supervisor' => $supervisor, 'caja' => $caja] = prepararCambioDeMostrador('110.00');
    $cambios = app(CambiarProductoVenta::class);
    $lineas = [['indice' => 0, 'cantidad' => '1']];
    $entrega = ['producto_variante_id' => $reemplazo->id, 'cantidad' => '1'];
    $resumen = $cambios->revisar($venta, $lineas, $entrega, '', $supervisor);

    expect(fn () => $cambios->confirmar($venta, [
        'lineas' => $lineas, 'reemplazo' => $entrega, 'motivo' => 'Otra medida', 'revision' => $resumen['revision'],
        'metodo_pago' => 'efectivo', 'efectivo_recibido' => '14.00',
    ], $supervisor))->toThrow(ValidationException::class, 'El efectivo recibido no alcanza');

    expect($venta->devoluciones()->count())->toBe(0);
    expect(Venta::query()->count())->toBe(1);
    expect(MovimientoInventario::query()->where('tipo_movimiento', TipoMovimientoInventario::Devolucion)->exists())->toBeFalse();
    expect(InventarioStock::query()->where('producto_variante_id', $venta->detalles()->sole()->producto_variante_id)->sole()->cantidad_actual)->toBe('4.0000');
    expect(app(ArqueoCajaSesion::class)->de($caja)['total_cobros'])->toBe('0.00');
});

it('confirma el cambio desde el detalle y muestra ambas piezas en el historial', function () {
    ['venta' => $venta, 'reemplazo' => $reemplazo, 'supervisor' => $supervisor, 'cajaOriginal' => $cajaOriginal, 'caja' => $caja] = prepararCambioDeMostrador('110.00');
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    $pagina = Livewire::actingAs($supervisor, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $cajaOriginal->id, 'supervision' => true]);
    $pagina->mountAction([TestAction::make('verDetalle')->table($venta), TestAction::make('cambiarProducto')])
        ->assertActionMounted('cambiarProducto')
        ->fillForm([
            'lineas' => [['indice' => 0, 'cantidad' => '1']],
            'reemplazo' => ['producto_variante_id' => $reemplazo->id, 'cantidad' => '1'],
            'motivo' => 'Otra medida',
        ]);
    $wizard = $pagina->instance()->getSchema($pagina->instance()->getMountedActionSchemaName())->getComponents()[0];
    $pagina->call('callSchemaComponentMethod', $wizard->getKey(), 'nextStep', ['currentStepIndex' => 0])
        ->assertDispatched('next-wizard-step')
        ->assertSchemaStateSet(['resumen.diferencia' => '15.00'])
        ->fillForm(['metodo_pago' => 'efectivo', 'efectivo_recibido' => '20', 'confirmado' => true])
        ->callMountedAction()
        ->assertNotified('Cambio registrado');

    $cambio = $venta->devoluciones()->sole();
    $nueva = $cambio->ventaReemplazo;
    expect($nueva->aplicacionesPago()->where('es_compensacion_cambio', false)->sole()->pago->cambio_entregado)->toBe('5.00');
    $detalle = $pagina->instance()->getMountedAction()->getModalContent()->render();
    expect($detalle)->toContain('Cambio completado', 'Aplicado de la venta original', 'Valor del reemplazo',
        'Todas las piezas volvieron', 'Ver reemplazo', 'Cambio registrado', 'Recibido', 'Entregado', $nueva->codigo, 'Diferencia cobrada de BOB 15.00');
    $pagina->mountAction(TestAction::make('verReemplazo')->arguments(['nota' => $cambio->id]))->assertActionMounted('verReemplazo');
    expect($pagina->instance()->getMountedAction()->getRecord()->is($nueva))->toBeTrue();

    $cajaNueva = Livewire::actingAs($supervisor, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $caja->id, 'supervision' => true])
        ->assertSee('Reemplazo')
        ->mountAction([TestAction::make('verDetalle')->table($nueva), TestAction::make('verVentaOriginal')])
        ->assertActionMounted('verVentaOriginal');
    expect($cajaNueva->instance()->getMountedAction()->getRecord()->is($venta))->toBeTrue();
});

it('mantiene disponibles solo las piezas conservadas tras una devolución parcial', function () {
    $this->freezeTime();
    ['supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $rodamiento, 'ocasional' => $ocasional] = negocioConMostrador('piezas-conservadas');
    $filtro = ProductoVariante::factory()->create(['sku' => 'FILTRO-CONSERVADO', 'precio_venta' => '35.00']);
    NegocioConfiguracion::query()->firstOrFail()->update([
        'acepta_devoluciones' => true, 'devolucion_permite_cambio' => true, 'devolucion_permite_reembolso' => true,
    ]);
    cargarStock($rodamiento, $almacen, $supervisor, $ocasional);
    cargarStock($filtro, $almacen, $supervisor, $ocasional);
    $caja = app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $supervisor);
    $venta = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id,
        'lineas' => [
            ['producto_variante_id' => $rodamiento->id, 'cantidad' => '1'],
            ['producto_variante_id' => $filtro->id, 'cantidad' => '1'],
        ],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '135.00']],
    ], $supervisor)['venta'];
    $devoluciones = app(RegistrarDevolucion::class);
    $devolver = function (int $indice) use ($devoluciones, $venta, $supervisor): void {
        $lineas = [['indice' => $indice, 'cantidad' => '1']];
        $resumen = $devoluciones->revisar($venta, $lineas, 'reembolso', '', $supervisor);
        $devoluciones->confirmar($venta, $lineas, 'reembolso', 'Pieza devuelta', '', $resumen['revision'], $supervisor);
    };
    $devolver(0);

    expect($venta->refresh()->estado)->toBe(EstadoVenta::Completada);
    expect($venta->totalVigente())->toBe('35.00');
    expect(InventarioStock::query()->where('producto_variante_id', $filtro->id)->sole()->cantidad_actual)->toBe('4.0000');

    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    $pagina = Livewire::actingAs($supervisor, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $caja->id]);
    $detalle = TestAction::make('verDetalle')->table($venta);
    foreach (['devolverVenta', 'cambiarProducto'] as $accion) {
        $pagina->assertActionEnabled([$detalle, TestAction::make($accion)])
            ->mountAction([$detalle, TestAction::make($accion)])
            ->assertActionMounted($accion)
            ->assertSchemaStateSet(function (array $estado): void {
                expect(collect($estado['lineas'])->pluck('indice')->all())->toBe([1]);
            })->unmountAction()->unmountAction();
    }

    $devolver(1);
    $pagina->call('$refresh')
        ->assertActionDisabled([$detalle, TestAction::make('devolverVenta')])
        ->assertActionDisabled([$detalle, TestAction::make('cambiarProducto')]);
    expect($venta->refresh()->totalVigente())->toBe('0.00');
    expect($venta->estado)->toBe(EstadoVenta::Completada);
});

it('no vende sin caja abierta', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('sincaja');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');

    expect(fn () => app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '100']],
    ], $cajero))->toThrow(ValidationException::class);
});

it('no deja stock negativo ni vender más de lo disponible', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('sinstock');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    expect(fn () => app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '2']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '200']],
    ], $cajero))->toThrow(ValidationException::class);
});

it('exige supervisor si el descuento pasa el tope', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('descuento');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    expect(fn () => app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad' => '1',
            'tipo_descuento' => TipoDescuento::Porcentaje->value,
            'valor_descuento' => '20',
        ]],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '80']],
    ], $cajero))->toThrow(ValidationException::class);
});

it('aplica descuento de la venta en porcentaje', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('desc-venta-pct');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    $venta = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'tipo_descuento' => TipoDescuento::Porcentaje->value,
        'valor_descuento' => '10',
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '90']],
    ], $cajero)['venta'];

    expect($venta->subtotal)->toBe('100.00')
        ->and($venta->tipo_descuento)->toBe(TipoDescuento::Porcentaje)
        ->and($venta->valor_descuento)->toBe('10.00')
        ->and($venta->descuento)->toBe('10.00')
        ->and($venta->total)->toBe('90.00');
});

it('aplica descuento de la venta en importe', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('desc-venta-imp');

    NegocioConfiguracion::query()->update(['descuento_max_importe' => '20']);
    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    $venta = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'tipo_descuento' => TipoDescuento::Importe->value,
        'valor_descuento' => '15',
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '85']],
    ], $cajero)['venta'];

    expect($venta->tipo_descuento)->toBe(TipoDescuento::Importe)
        ->and($venta->valor_descuento)->toBe('15.00')
        ->and($venta->descuento)->toBe('15.00')
        ->and($venta->total)->toBe('85.00');
});

it('exige supervisor si el descuento de la venta pasa el tope', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('desc-venta-tope');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    expect(fn () => app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'tipo_descuento' => TipoDescuento::Porcentaje->value,
        'valor_descuento' => '20',
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '80']],
    ], $cajero))->toThrow(ValidationException::class);
});

it('no deja descuento de venta mayor al total', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('desc-venta-neg');

    NegocioConfiguracion::query()->update(['descuento_max_importe' => '200']);
    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    expect(fn () => app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'tipo_descuento' => TipoDescuento::Importe->value,
        'valor_descuento' => '150',
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '0']],
    ], $cajero))->toThrow(ValidationException::class);
});

it('exige el comprobante del QR si la configuración lo pide', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('qr-comprobante');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    NegocioConfiguracion::query()->update(['comprobante_obligatorio_qr_externo' => true]);

    expect(fn () => app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [[
            'metodo_pago' => MetodoPago::QrExterno->value,
            'monto' => '100',
        ]],
    ], $cajero))->toThrow(ValidationException::class);

    app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [[
            'metodo_pago' => MetodoPago::QrExterno->value,
            'monto' => '100',
            'comprobante_archivo_path' => 'pagos/qr-1.pdf',
        ]],
    ], $cajero);

    expect(Pago::query()->value('comprobante_archivo_path'))->toBe('pagos/qr-1.pdf');
});

it('muestra el adjunto del QR y lo exige desde el mostrador si está configurado', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('qr-adjunto-ui');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    NegocioConfiguracion::query()->update(['comprobante_obligatorio_qr_externo' => true]);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->set('pagos.0.metodo_pago', MetodoPago::QrExterno->value)
        ->set('pagos.0.monto', '100')
        ->set('lineas', [[
            'producto_variante_id' => $variante->id,
            'sku' => $variante->sku,
            'nombre' => 'Pieza',
            'cantidad' => '1',
            'precio_unitario' => '100.00',
            'nota_precio' => '',
            'precio_costo' => '40.0000',
            'tipo_descuento' => '',
            'valor_descuento' => '',
            'disponible' => '1',
            'unidad_simbolo' => 'pza',
        ]])
        ->assertSee('Adjunto')
        ->assertSee('PDF o imagen')
        ->call('completar')
        ->assertHasErrors(['pagos.0.comprobante']);
});

it('permite cobro mixto y guarda path de comprobante', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('mixto');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    $resultado = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [
            [
                'metodo_pago' => MetodoPago::Efectivo->value,
                'monto' => '40',
            ],
            [
                'metodo_pago' => MetodoPago::Transferencia->value,
                'monto' => '60',
                'numero_referencia_banco' => 'TRX-1',
                'comprobante_archivo_path' => 'pagos/trx-1.pdf',
            ],
        ],
    ], $cajero);

    $pagos = Pago::query()->orderBy('id')->get();

    expect($resultado['venta']->total)->toBe('100.00')
        ->and($pagos)->toHaveCount(2)
        ->and($pagos[0]->caja_sesion_id)->not->toBeNull()
        ->and($pagos[1]->caja_sesion_id)->toBe($pagos[0]->caja_sesion_id)
        ->and($pagos[1]->comprobante_archivo_path)->toBe('pagos/trx-1.pdf')
        ->and($pagos[1]->numero_referencia_banco)->toBe('TRX-1');
});

it('exige la referencia solo en el medio que la pide', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('mixto-validacion');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->call('agregarPago')
        ->assertSet('pagos.1.metodo_pago', MetodoPago::Efectivo->value)
        ->set('pagos.0.metodo_pago', MetodoPago::Transferencia->value)
        ->set('pagos.0.monto', '40')
        ->set('pagos.1.monto', '60')
        ->set('lineas', [[
            'producto_variante_id' => $variante->id,
            'sku' => $variante->sku,
            'nombre' => 'Pieza',
            'cantidad' => '1',
            'precio_unitario' => '100.00',
            'precio_costo' => '40.0000',
            'tipo_descuento' => '',
            'valor_descuento' => '',
            'disponible' => '1',
            'unidad_simbolo' => 'pza',
        ]]);

    expect(substr_count($pagina->html(), 'Adjunto'))->toBe(1)
        ->and(substr_count($pagina->html(), 'Referencia / voucher'))->toBe(1);

    $pagina->call('completar')
        ->assertHasErrors(['pagos.0.numero_referencia_banco'])
        ->assertHasNoErrors(['pagos.1.numero_referencia_banco']);
});

it('pide confirmación al elegir un mayorista y cobra la lista elegida', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('precio-mayor');

    cargarStock($variante, $almacen, $cajero, $ocasional, '2');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    $mayorista = Cliente::factory()->mayor()->create(['nombres' => 'Taller', 'apellidos' => 'Rápido']);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->set('busqueda', $variante->sku)
        ->assertSet('resultadosBusqueda.0.precio', '100.00')
        ->assertSet('resultadosBusqueda.0.nota_precio', 'mayor 80.00')
        ->call('seleccionarResultado', $variante->id)
        ->assertSet('lineas.0.precio_unitario', '100.00')
        ->assertSet('lineas.0.nota_precio', 'mayor 80.00')
        ->call('seleccionarCliente', $mayorista->id)
        ->assertSet('mostrarEleccionPrecio', true)
        ->assertSet('clienteId', null)
        ->assertSet('lineas.0.precio_unitario', '100.00')
        ->assertSee('está definido como mayorista')
        ->call('confirmarListaPrecio', 'mayor')
        ->assertSet('mostrarEleccionPrecio', false)
        ->assertSet('clienteId', $mayorista->id)
        ->assertSet('listaPrecio', 'mayor')
        ->assertSet('lineas.0.precio_unitario', '80.00')
        ->assertSet('lineas.0.nota_precio', 'menor 100.00')
        ->assertSee('Mayorista · esta venta a mayor')
        ->call('seleccionarCliente', $mayorista->id)
        ->assertSet('mostrarEleccionPrecio', false)
        ->call('quitarCliente')
        ->assertSet('clienteId', null)
        ->assertSet('listaPrecio', 'menor')
        ->assertSet('lineas.0.precio_unitario', '100.00')
        ->call('seleccionarCliente', $mayorista->id)
        ->call('confirmarListaPrecio', 'mayor')
        ->set('pagos.0.monto', '80')
        ->call('completar')
        ->assertSet('ultimoCodigo', 'VNT-000001');

    expect(Venta::query()->first()?->total)->toBe('80.00')
        ->and($pagina->get('clienteId'))->toBe($mayorista->id);
});

it('cancela la elección del mayorista y puede cobrarle al por menor', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('precio-cancelar');

    cargarStock($variante, $almacen, $cajero, $ocasional, '2');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    $mayorista = Cliente::factory()->mayor()->create();
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->call('seleccionarResultado', $variante->id)
        ->call('seleccionarCliente', $mayorista->id)
        ->call('cancelarEleccionPrecio')
        ->assertSet('mostrarEleccionPrecio', false)
        ->assertSet('clienteId', null)
        ->assertSet('lineas.0.precio_unitario', '100.00')
        ->call('seleccionarCliente', $mayorista->id)
        ->call('confirmarListaPrecio', 'menor')
        ->assertSet('clienteId', $mayorista->id)
        ->assertSet('listaPrecio', 'menor')
        ->assertSet('lineas.0.precio_unitario', '100.00')
        ->assertSee('Mayorista · esta venta a menor')
        ->set('pagos.0.monto', '100')
        ->call('completar')
        ->assertSet('ultimoCodigo', 'VNT-000001');

    expect(Venta::query()->value('total'))->toBe('100.00');
});

it('cambia a precio mayor sin cliente', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('precio-sin-cliente');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->call('seleccionarResultado', $variante->id)
        ->assertSet('mostrarEleccionPrecio', false)
        ->call('cambiarListaPrecio', 'mayor')
        ->assertSet('listaPrecio', 'mayor')
        ->assertSet('lineas.0.precio_unitario', '80.00')
        ->assertSet('lineas.0.nota_precio', 'menor 100.00')
        ->set('busqueda', $variante->sku)
        ->assertSet('resultadosBusqueda.0.precio', '80.00');
});

it('hace compra flash con stock en cero y luego vende', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante] = negocioConMostrador('flash');

    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    expect(InventarioStock::query()->where('producto_variante_id', $variante->id)->value('cantidad_actual'))->toBe('0.0000');

    $flash = app(CompraFlash::class)->ejecutar([
        'almacen_id' => $almacen->id,
        'producto_variante_id' => $variante->id,
        'cantidad' => '2',
        'costo_unitario' => '35',
        'confirmo_costos' => true,
    ], $cajero);

    expect($flash['orden']->es_compra_flash)->toBeTrue()
        ->and(MovimientoInventario::query()->where('tipo_movimiento', TipoMovimientoInventario::EntradaFlashEmergencia)->count())->toBe(1)
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->value('cantidad_actual'))->toBe('2.0000');

    $resultado = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::QrExterno->value, 'monto' => '100']],
    ], $cajero);

    expect($resultado['venta']->codigo)->toStartWith('VNT-')
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->value('cantidad_actual'))->toBe('1.0000');
});

it('crea producto nuevo en compra flash', function () {
    ['cajero' => $cajero, 'almacen' => $almacen] = negocioConMostrador('flashnuevo');

    $flash = app(CompraFlash::class)->ejecutar([
        'almacen_id' => $almacen->id,
        'cantidad' => '1',
        'costo_unitario' => '12.5',
        'confirmo_costos' => true,
        'producto_nuevo' => [
            'nombre' => 'Junta flash',
            'sku' => 'JF-1',
            'precio_venta' => '25',
        ],
    ], $cajero);

    expect($flash['variante']->sku)->toBe('JF-1')
        ->and(InventarioStock::query()->where('producto_variante_id', $flash['variante']->id)->value('cantidad_actual'))->toBe('1.0000');
});

it('no permite actualizar una venta completada vía policy', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('noedit');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    $venta = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '100']],
    ], $cajero)['venta'];

    expect($cajero->can('update', $venta))->toBeFalse();
});

it('sugiere productos al escribir y los agrega al carrito desde el mostrador', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('busqueda');

    $variante->productoMaestro->update(['nombre' => 'CARBURADOR TOYOTA COROLLA 83']);
    $variante->update(['sku' => '21000-134200', 'codigo_barras' => '779000111222']);

    cargarStock($variante, $almacen, $cajero, $ocasional, '4');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '20'], $cajero);

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->set('busqueda', 'carbura')
        ->assertSet('resultadosBusqueda.0.sku', '21000-134200')
        ->call('seleccionarResultado', $variante->id)
        ->assertSet('busqueda', '')
        ->assertCount('lineas', 1)
        ->assertSet('lineas.0.sku', '21000-134200');
});

it('muestra el símbolo de la unidad de stock en la búsqueda y el carrito', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('unidad-stock');

    $kilo = UnidadMedida::query()->where('simbolo', 'kg')->firstOrFail();
    $variante->productoMaestro->update(['unidad_base_id' => $kilo->id]);

    cargarStock($variante, $almacen, $cajero, $ocasional, '2');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->set('busqueda', $variante->sku)
        ->assertSet('resultadosBusqueda.0.unidad_simbolo', 'kg')
        ->assertSee('kg')
        ->call('seleccionarResultado', $variante->id)
        ->assertSet('lineas.0.unidad_simbolo', 'kg')
        ->set('lineas.0.cantidad', '0.7')
        ->assertSee('0.7 kg')
        ->call('cambiarCantidad', 0, 1)
        ->assertSet('lineas.0.cantidad', '1.7')
        ->call('cambiarCantidad', 0, -1)
        ->assertSet('lineas.0.cantidad', '0.7')
        ->call('compactarCantidadVisible', 0)
        ->assertSet('lineas.0.cantidad', '0.7');
});

it('agrega por código de barras exacto con Enter en el mostrador', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('escaneo');

    $variante->update(['codigo_barras' => '7896543210987']);

    cargarStock($variante, $almacen, $cajero, $ocasional, '2');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->set('busqueda', '7896543210987')
        ->call('buscarYAgregar')
        ->assertCount('lineas', 1)
        ->assertSet('lineas.0.producto_variante_id', $variante->id)
        ->assertSet('busqueda', '')
        ->assertSet('resultadosBusqueda', []);
});

it('no lista el catálogo al abrir la búsqueda avanzada vacía', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('avanzada-vacia');

    cargarStock($variante, $almacen, $cajero, $ocasional, '2');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->call('abrirBusquedaAvanzada')
        ->assertSet('mostrarBusquedaAvanzada', true)
        ->assertSet('resultadosAvanzados', [])
        ->assertSet('totalAvanzados', 0)
        ->assertSee('Escribí un SKU, nombre o elegí un filtro')
        ->set('busquedaAvanzada', $variante->sku)
        ->assertCount('resultadosAvanzados', 1)
        ->assertSet('resultadosAvanzados.0.sku', $variante->sku);
});

it('abre el modal avanzado y filtra rodamientos por sello y stock del almacén', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'ocasional' => $ocasional] = negocioConMostrador('avanzada');

    $otroAlmacen = Almacen::factory()->create(['nombre' => 'Fondo']);
    $categoria = Categoria::factory()->create(['nombre' => 'Rodamientos']);
    CategoriaAtributo::factory()->lista(['2RS', 'ZZ'])->create([
        'categoria_id' => $categoria->id,
        'clave' => 'sello',
        'etiqueta' => 'Sello',
    ]);
    $producto = ProductoMaestro::factory()->create([
        'categoria_id' => $categoria->id,
        'nombre' => 'Rodamiento 6204',
    ]);
    $conStock = ProductoVariante::factory()->create([
        'producto_maestro_id' => $producto->id,
        'sku' => '6305-2RS',
        'precio_venta' => '15.00',
        'atributos_json' => ['sello' => '2RS'],
    ]);
    $sinStock = ProductoVariante::factory()->create([
        'producto_maestro_id' => $producto->id,
        'sku' => '6305-ZZ',
        'precio_venta' => '16.00',
        'atributos_json' => ['sello' => 'ZZ'],
    ]);

    cargarStock($conStock, $almacen, $cajero, $ocasional, '3');
    cargarStock($sinStock, $otroAlmacen, $cajero, $ocasional, '8');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->set('busqueda', '6305')
        ->call('abrirBusquedaAvanzada')
        ->assertSet('mostrarBusquedaAvanzada', true)
        ->assertSet('busquedaAvanzada', '6305')
        ->assertSet('almacenId', $almacen->id)
        ->set('filtroCategoriaId', $categoria->id)
        ->assertSet('atributosVisibles', [])
        ->call('agregarFiltroAtributo', 'sello')
        ->assertSet('atributosVisibles', ['sello'])
        ->set('filtrosAtributos.sello', '2RS');

    expect(collect($pagina->get('resultadosAvanzados'))->pluck('sku')->all())->toBe(['6305-2RS'])
        ->and($pagina->get('resultadosAvanzados')[0]['sin_stock'])->toBeFalse();

    $pagina->set('filtroDisponibilidad', 'sin_stock')
        ->set('filtrosAtributos.sello', 'ZZ');

    expect(collect($pagina->get('resultadosAvanzados'))->pluck('sku')->all())->toBe(['6305-ZZ'])
        ->and($pagina->get('resultadosAvanzados')[0]['sin_stock'])->toBeTrue();

    $pagina->call('seleccionarResultado', $sinStock->id)
        ->assertSet('mostrarFlash', true)
        ->assertSet('flashVarianteId', $sinStock->id)
        ->assertSet('mostrarBusquedaAvanzada', false);
});

it('sugiere clientes por nombre o documento y los selecciona en el mostrador', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConMostrador('clientes-buscar');

    $juan = Cliente::factory()->personaNatural()->create([
        'nombres' => 'Juan Carlos',
        'apellidos' => 'Pérez',
        'telefono' => '71345678',
    ]);
    $ana = Cliente::factory()->personaNatural()->create([
        'nombres' => 'Ana',
        'apellidos' => 'Pérez Morales',
    ]);
    $otro = Cliente::factory()->personaNatural()->create([
        'nombres' => 'Luis',
        'apellidos' => 'Gómez',
    ]);

    $nit = TipoDocumentoIdentidad::query()->where('codigo', 'nit')->firstOrFail();
    ClienteDocumento::factory()->create([
        'cliente_id' => $juan->id,
        'tipo_documento_identidad_id' => $nit->id,
        'valores_json' => ['numero' => '7845123'],
    ]);

    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->call('abrirCliente')
        ->assertSet('mostrarCliente', true)
        ->set('busquedaCliente', 'perez');

    expect(collect($pagina->get('resultadosCliente'))->pluck('id')->all())
        ->toContain($juan->id)
        ->toContain($ana->id)
        ->not->toContain($otro->id);

    $pagina->set('busquedaCliente', '7845123');

    expect(collect($pagina->get('resultadosCliente'))->pluck('id')->all())->toBe([$juan->id]);

    $pagina->call('seleccionarCliente', $juan->id)
        ->assertSet('clienteId', $juan->id)
        ->assertSet('mostrarCliente', false);
});

it('registra un cliente rápido desde el mostrador y lo deja seleccionado', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConMostrador('cliente-nuevo');

    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->call('abrirCliente')
        ->set('busquedaCliente', 'Luis Pérez Gómez')
        ->call('abrirRegistroCliente')
        ->assertSet('mostrandoRegistroCliente', true)
        ->assertSet('clienteTipo', 'persona_natural')
        ->assertSet('clienteNombres', 'Luis Pérez')
        ->assertSet('clienteApellidos', 'Gómez')
        ->set('clienteDocumento', '8765432')
        ->set('clienteTelefono', '71345678')
        ->set('clienteEmail', 'luis@example.com')
        ->call('registrarClienteRapido')
        ->assertSet('mostrarCliente', false);

    $cliente = Cliente::query()->where('email', 'luis@example.com')->first();

    expect($cliente)->not->toBeNull()
        ->and($cliente->tipo_cliente)->toBe(TipoCliente::PersonaNatural)
        ->and($cliente->nombres)->toBe('Luis Pérez')
        ->and($cliente->apellidos)->toBe('Gómez')
        ->and($cliente->telefono)->toBe('71345678')
        ->and($pagina->get('clienteId'))->toBe($cliente->id)
        ->and($cliente->documentos)->toHaveCount(1)
        ->and($cliente->documentos->first()->valores_json['numero'])->toBe('8765432');
});

it('selecciona el cliente existente si el documento ya está registrado', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConMostrador('cliente-existe');

    $existente = Cliente::factory()->personaNatural()->create([
        'nombres' => 'María',
        'apellidos' => 'Pérez',
        'telefono' => '70000000',
    ]);
    $nit = TipoDocumentoIdentidad::query()->where('codigo', 'nit')->firstOrFail();
    ClienteDocumento::factory()->create([
        'cliente_id' => $existente->id,
        'tipo_documento_identidad_id' => $nit->id,
        'valores_json' => ['numero' => '9988776'],
    ]);

    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->call('abrirCliente')
        ->call('abrirRegistroCliente')
        ->set('clienteNombres', 'Otra')
        ->set('clienteApellidos', 'Persona')
        ->set('clienteDocumento', '9988776')
        ->set('clienteTelefono', '71111111')
        ->call('registrarClienteRapido')
        ->assertSet('clienteId', $existente->id);

    expect(Cliente::query()->count())->toBe(1);
});

it('no registra un cliente rápido si falta el apellido', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConMostrador('cliente-incompleto');

    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->call('abrirCliente')
        ->call('abrirRegistroCliente')
        ->set('clienteNombres', 'Luis')
        ->set('clienteDocumento', '1112223')
        ->set('clienteTelefono', '70000001')
        ->call('registrarClienteRapido')
        ->assertSet('mostrandoRegistroCliente', true)
        ->assertSet('clienteId', null);

    expect(Cliente::query()->count())->toBe(0);
});

it('aplica el descuento de venta desde el mostrador', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('desc-mostrador');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->assertSee('Agregar descuento')
        ->assertDontSee('Descuento de la venta')
        ->call('seleccionarResultado', $variante->id)
        ->call('mostrarCampoDescuentoVenta')
        ->assertSet('mostrarDescuentoVenta', true)
        ->assertSet('tipoDescuentoVenta', TipoDescuento::Porcentaje->value)
        ->assertSee('Descuento de la venta')
        ->set('valorDescuentoVenta', '10')
        ->set('pagos.0.monto', '90')
        ->call('completar')
        ->assertSet('ultimoCodigo', 'VNT-000001')
        ->assertSet('mostrarDescuentoVenta', false);

    $venta = Venta::query()->first();

    expect($venta)->not->toBeNull()
        ->and($venta->tipo_descuento)->toBe(TipoDescuento::Porcentaje)
        ->and($venta->descuento)->toBe('10.00')
        ->and($venta->total)->toBe('90.00');
});

it('registra una empresa rápida desde el mostrador', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConMostrador('cliente-empresa');

    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->call('abrirCliente')
        ->call('abrirRegistroCliente')
        ->set('clienteTipo', 'empresa')
        ->set('clienteRazonSocial', 'Taller Norte SRL')
        ->set('clienteDocumento', '102938475')
        ->set('clienteTelefono', '33334444')
        ->call('registrarClienteRapido')
        ->assertSet('mostrarCliente', false);

    $cliente = Cliente::query()->where('razon_social', 'Taller Norte SRL')->first();

    expect($cliente)->not->toBeNull()
        ->and($cliente->tipo_cliente)->toBe(TipoCliente::Empresa)
        ->and($cliente->nombres)->toBeNull()
        ->and($pagina->get('clienteId'))->toBe($cliente->id)
        ->and($cliente->documentos->first()->valores_json['numero'])->toBe('102938475');
});

it('exige confirmar costo en entrada express sin mover caja', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante] = negocioConMostrador('express-ui');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '50'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($cajero, 'tenant')->test(MostradorDelNegocio::class)
        ->call('seleccionarResultado', $variante->id)
        ->assertSet('mostrarFlash', true)
        ->set('flashCantidad', '2')
        ->set('flashCosto', '15')
        ->call('ejecutarFlash')
        ->assertSet('mostrarFlash', true)
        ->set('flashConfirmo', true)
        ->call('ejecutarFlash')
        ->assertSet('mostrarFlash', false)
        ->assertHasNoErrors();

    expect($pagina->get('lineas'))->toHaveCount(1);
    expect($variante->fresh()->precio_costo)->toBe('15.0000');
    expect(InventarioStock::query()->where('producto_variante_id', $variante->id)->where('almacen_id', $almacen->id)->value('cantidad_actual'))->toBe('2.0000');
    expect(Pago::query()->count())->toBe(0);
    expect(MovimientoInventario::query()->count())->toBe(1);
    $pagina->call('ejecutarFlash');
    expect(MovimientoInventario::query()->count())->toBe(1);
});

it('crea una pieza express desde el mostrador con ficha mínima y SKU automático', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConMostrador('express-minima');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')->test(MostradorDelNegocio::class)
        ->call('abrirFlashNuevo')
        ->set('flashNombre', 'Bujía express')
        ->set('flashPrecioVenta', '28')
        ->set('flashConfirmo', true)
        ->set('flashCosto', '15')
        ->call('ejecutarFlash')
        ->assertSet('mostrarFlash', false);

    $pieza = ProductoMaestro::query()->where('nombre', 'Bujía express')->firstOrFail();
    expect($pieza->categoria->nombre)->toBe('General');
    expect($pieza->unidadBase->simbolo)->toBe('pza');
    expect($pieza->marca_id)->toBeNull();
    expect(ProductoVariante::query()->where('producto_maestro_id', $pieza->id)->firstOrFail()->sku)->toStartWith('FLASH-');
});

it('reasigna proveedor desde compras sin duplicar stock ni alterar costo o caja', function () {
    ['supervisor' => $supervisor, 'almacen' => $almacen, 'variante' => $variante] = negocioConMostrador('express-proveedor');
    $flash = app(CompraFlash::class)->ejecutar(['almacen_id' => $almacen->id, 'producto_variante_id' => $variante->id, 'cantidad' => '2', 'costo_unitario' => '15', 'confirmo_costos' => true], $supervisor);
    $proveedor = Proveedor::factory()->create();
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')->test(ViewOrdenCompra::class, ['record' => $flash['orden']->id])
        ->callAction('reasignarProveedor', data: ['proveedor_id' => $proveedor->id])
        ->assertHasNoActionErrors()
        ->assertRedirect(OrdenCompraResource::getUrl('view', ['record' => $flash['orden']]));

    Livewire::actingAs($supervisor, 'tenant')->test(ViewOrdenCompra::class, ['record' => $flash['orden']->id])
        ->assertFormSet(['proveedor_id' => $proveedor->id])
        ->assertActionHidden('reasignarProveedor');

    expect($flash['orden']->fresh()->proveedor_id)->toBe($proveedor->id);
    expect($flash['orden']->fresh()->total)->toBe('30.00');
    expect(InventarioStock::query()->where('producto_variante_id', $variante->id)->where('almacen_id', $almacen->id)->value('cantidad_actual'))->toBe('2.0000');
    expect($variante->fresh()->precio_costo)->toBe('15.0000');
    expect(MovimientoInventario::query()->count())->toBe(1);
    expect(Pago::query()->count())->toBe(0);
});

it('impide al cajero reasignar proveedor de una express', function () {
    ['cajero' => $cajero, 'almacen' => $almacen, 'variante' => $variante] = negocioConMostrador('express-permiso');
    $flash = app(CompraFlash::class)->ejecutar(['almacen_id' => $almacen->id, 'producto_variante_id' => $variante->id, 'cantidad' => '1', 'costo_unitario' => '15', 'confirmo_costos' => true], $cajero);
    $proveedor = Proveedor::factory()->create();

    expect(fn () => app(ReasignarProveedorFlash::class)->ejecutar($flash['orden'], $proveedor->id, $cajero))->toThrow(HttpException::class);
    expect($flash['orden']->fresh()->proveedor_id)->not->toBe($proveedor->id);
});
it('rechaza costo inválido en express sin crear compra ni movimiento', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'variante' => $variante] = negocioConMostrador('express-invalida');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')->test(MostradorDelNegocio::class)
        ->call('seleccionarResultado', $variante->id)
        ->set('flashConfirmo', true)
        ->set('flashCosto', '0')
        ->call('ejecutarFlash')
        ->assertSet('mostrarFlash', true);

    expect(OrdenCompra::query()->count())->toBe(0);
    expect(MovimientoInventario::query()->count())->toBe(0);
    expect(Pago::query()->count())->toBe(0);
});

it('rechaza proveedor de otro negocio al reasignar express', function () {
    ['supervisor' => $supervisor, 'almacen' => $almacen, 'variante' => $variante] = negocioConMostrador('express-aislada');
    $tenant = tenant();
    $flash = app(CompraFlash::class)->ejecutar(['almacen_id' => $almacen->id, 'producto_variante_id' => $variante->id, 'cantidad' => '1', 'costo_unitario' => '15', 'confirmo_costos' => true], $supervisor);
    tenancy()->end();
    $otro = Tenant::factory()->create();
    tenancy()->initialize($otro);
    $proveedor = Proveedor::factory()->create();
    tenancy()->end();
    tenancy()->initialize($tenant);

    expect(fn () => app(ReasignarProveedorFlash::class)->ejecutar($flash['orden'], $proveedor->id, $supervisor))->toThrow(ValidationException::class);
    expect($flash['orden']->fresh()->proveedor_id)->not->toBe($proveedor->id);
});
