<?php

namespace App\Enums;

enum TipoMovimientoInventario: string
{
    case InventarioInicial = 'inventario_inicial';
    case EntradaCompra = 'entrada_compra';
    case SalidaVenta = 'salida_venta';
    case Devolucion = 'devolucion';
    case TrasladoSalida = 'traslado_salida';
    case TrasladoEntrada = 'traslado_entrada';
    case Ajuste = 'ajuste';
    case EntradaFlashEmergencia = 'entrada_flash_emergencia';
    case CorreccionCosto = 'correccion_costo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::InventarioInicial => 'Entrada por inventario inicial',
            self::EntradaCompra => 'Entrada por compra',
            self::SalidaVenta => 'Salida por venta',
            self::Devolucion => 'Devolución',
            self::TrasladoSalida => 'Salida por traslado',
            self::TrasladoEntrada => 'Entrada por traslado',
            self::Ajuste => 'Ajuste de inventario',
            self::EntradaFlashEmergencia => 'Entrada express',
            self::CorreccionCosto => 'Corrección de costo',
        };
    }
}
