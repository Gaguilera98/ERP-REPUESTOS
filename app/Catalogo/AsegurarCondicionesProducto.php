<?php

namespace App\Catalogo;

use App\Models\CondicionProducto;

class AsegurarCondicionesProducto
{
    public function handle(): void
    {
        foreach (['nuevo' => 'Nuevo', 'usado' => 'Usado'] as $codigo => $nombre) {
            CondicionProducto::query()->firstOrCreate(['codigo' => $codigo], ['nombre' => $nombre, 'orden' => $codigo === 'nuevo' ? 1 : 2]);
        }
    }
}
