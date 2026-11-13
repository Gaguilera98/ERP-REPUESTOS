<?php

namespace App\Enums;

enum TipoCajaChicaMovimiento: string
{
    case EgresoGasto = 'egreso_gasto';
    case EgresoVale = 'egreso_vale';
    case IngresoSobrante = 'ingreso_sobrante';
    case IngresoReposicion = 'ingreso_reposicion';
    case EgresoReposicion = 'egreso_reposicion';
    case Correccion = 'correccion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EgresoGasto => 'Gasto',
            self::EgresoVale => 'Vale',
            self::IngresoSobrante => 'Sobrante',
            self::IngresoReposicion => 'Reposición',
            self::EgresoReposicion => 'Salida a reposición',
            self::Correccion => 'Corrección',
        };
    }

    public function esSalida(): bool
    {
        return in_array($this, [self::EgresoGasto, self::EgresoVale, self::EgresoReposicion], true);
    }
}
