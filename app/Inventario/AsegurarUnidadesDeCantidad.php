<?php

namespace App\Inventario;

use App\Enums\MagnitudUnidad;
use App\Enums\UsoUnidad;
use App\Models\ProductoMaestro;
use App\Models\UnidadMedida;
use Illuminate\Support\Facades\DB;

class AsegurarUnidadesDeCantidad
{
    /**
     * Unidades con las que se cuenta el stock. El factor es contra la referencia de su familia.
     *
     * @var list<array{nombre: string, simbolo: string, magnitud: MagnitudUnidad, factor: string, decimales: int, es_referencia: bool}>
     */
    private const CATALOGO = [
        ['nombre' => 'Pieza', 'simbolo' => 'pza', 'magnitud' => MagnitudUnidad::Conteo, 'factor' => '1', 'decimales' => 0, 'es_referencia' => true],
        ['nombre' => 'Gramo', 'simbolo' => 'g', 'magnitud' => MagnitudUnidad::Masa, 'factor' => '1', 'decimales' => 0, 'es_referencia' => true],
        ['nombre' => 'Kilogramo', 'simbolo' => 'kg', 'magnitud' => MagnitudUnidad::Masa, 'factor' => '1000', 'decimales' => 3, 'es_referencia' => false],
        ['nombre' => 'Mililitro', 'simbolo' => 'ml', 'magnitud' => MagnitudUnidad::Volumen, 'factor' => '1', 'decimales' => 0, 'es_referencia' => true],
        ['nombre' => 'Litro', 'simbolo' => 'L', 'magnitud' => MagnitudUnidad::Volumen, 'factor' => '1000', 'decimales' => 3, 'es_referencia' => false],
        ['nombre' => 'Metro', 'simbolo' => 'm', 'magnitud' => MagnitudUnidad::Longitud, 'factor' => '1', 'decimales' => 2, 'es_referencia' => true],
        ['nombre' => 'Centímetro', 'simbolo' => 'cm', 'magnitud' => MagnitudUnidad::Longitud, 'factor' => '0.01', 'decimales' => 1, 'es_referencia' => false],
    ];

    public function handle(): void
    {
        foreach (self::CATALOGO as $definicion) {
            UnidadMedida::query()->firstOrCreate(
                ['simbolo' => $definicion['simbolo']],
                [
                    'nombre' => $definicion['nombre'],
                    'magnitud' => $definicion['magnitud'],
                    'uso' => UsoUnidad::Cantidad,
                    'factor_a_referencia' => $definicion['factor'],
                    'decimales' => $definicion['decimales'],
                    'es_referencia' => $definicion['es_referencia'],
                ],
            );
        }

        $piezaId = UnidadMedida::query()->where('simbolo', 'pza')->value('id');

        if ($piezaId === null) {
            return;
        }

        ProductoMaestro::query()
            ->whereNull('unidad_base_id')
            ->update(['unidad_base_id' => $piezaId]);
    }

    /**
     * Carga el catálogo en cada negocio que ya existe, sin el scope del tenant.
     */
    public function enTodosLosNegocios(): void
    {
        $conexion = DB::connection('pool_shared_1');

        if (! $conexion->getSchemaBuilder()->hasTable('negocio_configuracion')) {
            return;
        }

        $ahora = now();

        foreach ($conexion->table('negocio_configuracion')->pluck('tenant_id') as $tenantId) {
            foreach (self::CATALOGO as $definicion) {
                $existe = $conexion->table('unidades_medida')
                    ->where('tenant_id', $tenantId)
                    ->where('simbolo', $definicion['simbolo'])
                    ->exists();

                if ($existe) {
                    continue;
                }

                $conexion->table('unidades_medida')->insert([
                    'tenant_id' => $tenantId,
                    'nombre' => $definicion['nombre'],
                    'simbolo' => $definicion['simbolo'],
                    'magnitud' => $definicion['magnitud']->value,
                    'uso' => UsoUnidad::Cantidad->value,
                    'factor_a_referencia' => $definicion['factor'],
                    'decimales' => $definicion['decimales'],
                    'es_referencia' => $definicion['es_referencia'],
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }

            $piezaId = $conexion->table('unidades_medida')
                ->where('tenant_id', $tenantId)
                ->where('simbolo', 'pza')
                ->value('id');

            if ($piezaId === null || ! $conexion->getSchemaBuilder()->hasColumn('productos_maestros', 'unidad_base_id')) {
                continue;
            }

            $conexion->table('productos_maestros')
                ->where('tenant_id', $tenantId)
                ->whereNull('unidad_base_id')
                ->update(['unidad_base_id' => $piezaId]);
        }
    }
}
