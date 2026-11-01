<?php

namespace App\Enums;

enum TipoMovimientoInventario: string
{
    case EntradaCompra = 'entrada_compra';
    case SalidaVenta = 'salida_venta';
    case Devolucion = 'devolucion';
    case TrasladoSalida = 'traslado_salida';
    case TrasladoEntrada = 'traslado_entrada';
    case Ajuste = 'ajuste';
    case EntradaFlashEmergencia = 'entrada_flash_emergencia';
    case CorreccionCosto = 'correccion_costo';
}
