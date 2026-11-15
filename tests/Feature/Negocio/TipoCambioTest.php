<?php

use App\Catalogo\PublicarTipoCambio;
use App\Enums\ModoMoneda;
use App\Filament\Negocio\Pages\TipoDeCambio;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\CreateProductoMaestro;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\EditProductoMaestro;
use App\Models\Categoria;
use App\Models\NegocioConfiguracion;
use App\Models\ProductoVariante;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TipoCambio;
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

function negocioConDolar(): TenantUser
{
    tenancy()->initialize(Tenant::factory()->create());
    NegocioConfiguracion::query()->firstOrFail()->update(['modo_moneda' => ModoMoneda::ConDolar]);
    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('administrador');
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    return $supervisor;
}

it('publica desde la vista previa y ajusta cada referencia sin mover precios fijos ni el costo', function () {
    $supervisor = negocioConDolar();
    $mixto = ProductoVariante::factory()->create([
        'sku' => 'MIXTO', 'precio_venta' => 220, 'precio_mayor' => 187,
        'precio_mayor_dolar' => 17, 'precio_costo' => 100,
    ]);
    $referenciado = ProductoVariante::factory()->create([
        'precio_venta' => 220, 'precio_venta_dolar' => 20,
        'precio_mayor' => 187, 'precio_mayor_dolar' => 17,
    ]);
    $fijo = ProductoVariante::factory()->create(['precio_venta' => 99]);
    TipoCambio::factory()->create(['valor' => 11, 'user_id' => $supervisor->id]);

    Livewire::actingAs($supervisor, 'tenant')->test(TipoDeCambio::class)
        ->fillForm(['valor' => '12'])
        ->call('revisar')
        ->assertHasNoErrors()
        ->assertSee('187.00 → 204.00')
        ->call('publicar')
        ->assertHasNoErrors()
        ->assertNotified('Tipo de cambio publicado')
        ->assertSee('BOB 12.0000');

    expect($mixto->refresh()->precio_venta)->toBe('220.00')
        ->and($mixto->precio_mayor)->toBe('204.00')
        ->and($mixto->precio_costo)->toBe('100.0000');
    expect($referenciado->refresh()->precio_venta)->toBe('240.00')
        ->and($referenciado->precio_mayor)->toBe('204.00');
    expect($fijo->refresh()->precio_venta)->toBe('99.00');
    expect(TipoCambio::query()->count())->toBe(2);
});

it('señala los precios invertidos y conserva todo cuando el catálogo cambió desde la revisión', function () {
    $supervisor = negocioConDolar();
    $variante = ProductoVariante::factory()->create([
        'sku' => 'MIXTO', 'precio_venta' => 220, 'precio_mayor' => 187, 'precio_mayor_dolar' => 17,
    ]);
    $accion = app(PublicarTipoCambio::class);

    $conflicto = $accion->previsualizar('13');
    expect($conflicto['errores'][0]['problema'])->toBe('El precio Mayor supera al Menor.');
    expect(fn () => $accion->publicar('13', $conflicto['firma'], $supervisor))->toThrow(ValidationException::class, 'Corregí los precios marcados');

    $vista = $accion->previsualizar('12');
    $variante->update(['precio_venta' => 225]);
    expect(fn () => $accion->publicar('12', $vista['firma'], $supervisor))->toThrow(ValidationException::class, 'El catálogo o la cotización cambió');
    expect($variante->refresh()->precio_mayor)->toBe('187.00');
    expect(TipoCambio::query()->count())->toBe(0);

    $vista = $accion->previsualizar('12');
    $publicado = $accion->publicar('12', $vista['firma'], $supervisor);
    expect($accion->publicar('12', $vista['firma'], $supervisor)->id)->toBe($publicado->id);
    expect(TipoCambio::query()->count())->toBe(1);
});

it('permite configurar y luego quitar la referencia del Menor sin alterar el Mayor fijo', function () {
    $supervisor = negocioConDolar();
    TipoCambio::factory()->create(['valor' => 11, 'user_id' => $supervisor->id]);
    $categoria = Categoria::factory()->create();

    Livewire::actingAs($supervisor, 'tenant')->test(CreateProductoMaestro::class)
        ->fillForm([
            'nombre' => 'Rodamiento',
            'categoria_id' => $categoria->id,
            'sku' => 'USD-MENOR',
            'precio_venta_dolar' => '20',
            'precio_mayor' => '180',
        ])
        ->call('create')
        ->assertHasNoFormErrors();
    $variante = ProductoVariante::query()->where('sku', 'USD-MENOR')->firstOrFail();
    expect($variante->precio_venta)->toBe('220.00')
        ->and($variante->precio_mayor)->toBe('180.00');

    Livewire::actingAs($supervisor, 'tenant')->test(EditProductoMaestro::class, ['record' => $variante->producto_maestro_id])
        ->fillForm(['precio_venta_dolar' => null, 'precio_venta' => '225'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($variante->refresh()->precio_venta_dolar)->toBeNull()
        ->and($variante->precio_venta)->toBe('225.00')
        ->and($variante->precio_mayor)->toBe('180.00');
});

it('restringe la pantalla y la publicación al permiso asignado', function () {
    negocioConDolar();
    $cajero = TenantUser::factory()->create();
    $cajero->assignRole('vendedor');
    $accion = app(PublicarTipoCambio::class);
    $vista = $accion->previsualizar('11');

    Livewire::actingAs($cajero, 'tenant')->test(TipoDeCambio::class)->assertForbidden();
    expect(fn () => $accion->publicar('11', $vista['firma'], $cajero))->toThrow(HttpException::class);
    expect(TipoCambio::query()->count())->toBe(0);
});
