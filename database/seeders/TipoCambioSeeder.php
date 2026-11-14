<?php

namespace Database\Seeders;

use App\Catalogo\PublicarTipoCambio;
use App\Models\TenantUser;
use Illuminate\Database\Seeder;

class TipoCambioSeeder extends Seeder
{
    public function run(): void
    {
        $usuario = TenantUser::query()->role('supervisor')->firstOrFail();
        $precios = app(PublicarTipoCambio::class);
        $valor = $this->command->ask('¿Cuántas unidades de moneda local equivalen a 1 USD?');
        $vista = $precios->previsualizar($valor);

        if ($this->command->confirm("La cotización afectará {$vista['cantidad']} variantes. ¿Publicarla?")) {
            $precios->publicar($valor, $vista['firma'], $usuario);
        }
    }
}
