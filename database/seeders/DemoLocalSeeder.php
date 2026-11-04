<?php

namespace Database\Seeders;

use App\Enums\TipoCliente;
use App\Enums\TipoPrecioCliente;
use App\Models\Almacen;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Impuesto;
use App\Models\Marca;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Datos de prueba para un tenant local. No corre al crear el tenant.
 *
 * php artisan tenants:run db:seed --tenants=TU_TENANT_ID --option="--class=Database\\Seeders\\DemoLocalSeeder"
 */
class DemoLocalSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoLocalSeeder solo corre en local.');
        }

        if (! tenancy()->initialized) {
            throw new RuntimeException('Inicializá el tenant antes de correr DemoLocalSeeder.');
        }

        $this->call(CatalogoSeeder::class);

        Impuesto::query()->firstOrCreate(
            ['nombre' => 'IVA'],
            ['porcentaje' => '13.00', 'is_active' => true],
        );

        Proveedor::query()->firstOrCreate(
            ['nombre_empresa' => 'Distribuidora SKF Bolivia'],
            ['es_ocasional' => false, 'nit' => '1020304019'],
        );

        $this->sucursales();
        $this->clientes();
        $this->productos();
    }

    private function sucursales(): void
    {
        foreach ([
            ['nombre' => 'Centro', 'direccion' => 'Av. Principal 100', 'telefono' => '70000001', 'almacen' => 'Depósito Centro'],
            ['nombre' => 'Alto San Pedro', 'direccion' => 'Mercado Alto San Pedro', 'telefono' => '70000002', 'almacen' => 'Depósito San Pedro'],
        ] as $datos) {
            $sucursal = Sucursal::query()->firstOrCreate(
                ['nombre' => $datos['nombre']],
                [
                    'direccion' => $datos['direccion'],
                    'telefono' => $datos['telefono'],
                    'is_active' => true,
                ],
            );

            $almacen = Almacen::query()->firstOrCreate(
                ['nombre' => $datos['almacen']],
            );

            SucursalAlmacen::query()->firstOrCreate(
                [
                    'sucursal_id' => $sucursal->id,
                    'almacen_id' => $almacen->id,
                ],
                ['es_principal' => true],
            );
        }
    }

    private function clientes(): void
    {
        Cliente::query()->firstOrCreate(
            [
                'tipo_cliente' => TipoCliente::PersonaNatural,
                'nombres' => 'María',
                'apellidos' => 'Condori',
            ],
            [
                'razon_social' => null,
                'tipo_precio' => TipoPrecioCliente::Menor,
            ],
        );

        Cliente::query()->firstOrCreate(
            [
                'tipo_cliente' => TipoCliente::Empresa,
                'razon_social' => 'Taller Mecánico El Rápido',
            ],
            [
                'nombres' => null,
                'apellidos' => null,
                'tipo_precio' => TipoPrecioCliente::Mayor,
            ],
        );
    }

    private function productos(): void
    {
        $categoria = Categoria::query()->firstOrCreate(
            ['nombre' => 'Repuestos demo'],
        );

        $marca = Marca::query()->firstOrCreate(
            ['nombre' => 'SKF'],
            ['is_active' => true],
        );

        foreach ([
            ['nombre' => 'Rodamiento 6204-2RS', 'sku' => 'DEMO-6204-2RS', 'venta' => '45.00', 'mayor' => '38.00', 'costo' => '22.0000'],
            ['nombre' => 'Rodamiento 6205-ZZ', 'sku' => 'DEMO-6205-ZZ', 'venta' => '52.00', 'mayor' => '44.00', 'costo' => '26.0000'],
            ['nombre' => 'Filtro de aceite PF47', 'sku' => 'DEMO-PF47', 'venta' => '35.00', 'mayor' => '28.00', 'costo' => '15.0000'],
            ['nombre' => 'Pastilla de freno delantera', 'sku' => 'DEMO-PAD-DEL', 'venta' => '120.00', 'mayor' => '95.00', 'costo' => '60.0000'],
            ['nombre' => 'Bujía iridium IX22', 'sku' => 'DEMO-IX22', 'venta' => '28.00', 'mayor' => '22.00', 'costo' => '12.0000'],
        ] as $datos) {
            $producto = ProductoMaestro::query()->firstOrCreate(
                ['nombre' => $datos['nombre']],
                [
                    'categoria_id' => $categoria->id,
                    'marca_id' => $marca->id,
                    'is_active' => true,
                ],
            );

            ProductoVariante::query()->firstOrCreate(
                ['sku' => $datos['sku']],
                [
                    'producto_maestro_id' => $producto->id,
                    'precio_venta' => $datos['venta'],
                    'precio_mayor' => $datos['mayor'],
                    'precio_costo' => $datos['costo'],
                    'is_active' => true,
                ],
            );
        }
    }
}
