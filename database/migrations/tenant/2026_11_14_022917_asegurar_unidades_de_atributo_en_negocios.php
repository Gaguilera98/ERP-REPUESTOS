<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private const UNIDADES = [
        ['nombre' => 'Milímetro', 'simbolo' => 'mm', 'magnitud' => 'longitud'],
        ['nombre' => 'Pulgada', 'simbolo' => 'in', 'magnitud' => 'longitud'],
        ['nombre' => 'Voltio', 'simbolo' => 'V', 'magnitud' => 'electrico'],
        ['nombre' => 'Amperio', 'simbolo' => 'A', 'magnitud' => 'electrico'],
        ['nombre' => 'Bar', 'simbolo' => 'bar', 'magnitud' => 'presion'],
    ];

    public function up(): void
    {
        $conexion = DB::connection();
        $ahora = now();

        foreach ($conexion->table('negocio_configuracion')->select('id', 'tenant_id')->lazyById() as $negocio) {
            foreach (self::UNIDADES as $unidad) {
                $datos = [...$unidad, 'tenant_id' => $negocio->tenant_id, 'uso' => 'atributo'];
                $unidades = $conexion->table('unidades_medida');

                if ($unidades->insertOrIgnore([...$datos, 'created_at' => $ahora, 'updated_at' => $ahora]) === 1) {
                    continue;
                }

                if (! $unidades->where($datos)->exists()) {
                    Log::warning('La unidad base de ficha técnica coincide con una unidad existente de otra definición.', $datos);
                }
            }
        }
    }

    public function down(): void
    {
        // Las unidades pueden estar referenciadas por atributos desde el despliegue.
    }
};
