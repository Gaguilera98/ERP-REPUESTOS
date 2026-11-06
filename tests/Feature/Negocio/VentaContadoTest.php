<?php

use App\Caja\AbrirCajaSesion;
use App\Caja\ArqueoCajaSesion;
use App\Compras\GuardarOrdenCompra;
use App\Compras\ReasignarProveedorFlash;
use App\Compras\RecibirMercaderia;
use App\Enums\EstadoVenta;
use App\Enums\MetodoPago;
use App\Enums\SentidoPago;
use App\Enums\TipoCliente;
use App\Enums\TipoDescuento;
use App\Enums\TipoMovimientoInventario;
use App\Filament\Negocio\Pages\MostradorDelNegocio;
use App\Filament\Negocio\Resources\OrdenCompras\OrdenCompraResource;
use App\Filament\Negocio\Resources\OrdenCompras\Pages\ViewOrdenCompra;
use App\Livewire\Negocio\Caja\VentasDeSesion;
use App\Models\Almacen;
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
use App\Ventas\CompletarVentaContado;
use App\Ventas\CompraFlash;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
