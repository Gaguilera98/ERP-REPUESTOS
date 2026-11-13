<?php

namespace App\CajaChica;

final class Permisos
{
    public const PROPIA = 'Registrar:GastoCajaPropia';

    public const AJENA = 'Registrar:GastoCajaAjena';

    public const OPERAR = 'Operar:CajaChica';

    public const REVISAR = 'Revisar:CajaChica';

    public const CERRAR_AJENO = 'Cerrar:TurnoAjeno';

    public const CATEGORIAS = 'Administrar:CategoriaGasto';

    public const FONDOS = 'Administrar:FondoCajaChica';

    public const VER = 'View:CajaChica';

    /**
     * @return list<string>
     */
    public static function nombres(): array
    {
        return [
            self::PROPIA,
            self::AJENA,
            self::OPERAR,
            self::REVISAR,
            self::CERRAR_AJENO,
            self::CATEGORIAS,
            self::FONDOS,
            self::VER,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function etiquetas(): array
    {
        return [
            self::PROPIA => 'Registrar gastos en mi caja',
            self::AJENA => 'Registrar gastos en otras cajas',
            self::OPERAR => 'Operar caja chica independiente',
            self::REVISAR => 'Revisar y autorizar excepciones',
            self::CERRAR_AJENO => 'Cerrar un turno ajeno',
            self::CATEGORIAS => 'Administrar categorías de gasto',
            self::FONDOS => 'Administrar fondos de caja chica',
            self::VER => 'Ver caja chica',
        ];
    }
}
