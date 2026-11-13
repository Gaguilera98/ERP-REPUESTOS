<?php

namespace App\CajaChica;

use App\Caja\ArqueoCajaSesion;
use App\Compras\Montos;
use App\Models\CajaChicaFondo;
use App\Models\CajaSesion;

class SaldoEfectivo
{
    public function __construct(private ArqueoCajaSesion $arqueo) {}

    public function deSesion(CajaSesion $sesion): string
    {
        return $this->arqueo->de($sesion)['teorico_efectivo'];
    }

    public function deFondo(CajaChicaFondo $fondo): string
    {
        return Montos::dos((string) $fondo->saldo_actual);
    }
}
