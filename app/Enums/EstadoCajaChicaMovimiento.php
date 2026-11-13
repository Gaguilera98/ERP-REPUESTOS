<?php

namespace App\Enums;

enum EstadoCajaChicaMovimiento: string
{
    case PendienteComprobante = 'pendiente_comprobante';
    case PendienteRendir = 'pendiente_rendir';
    case RendidoCompleto = 'rendido_completo';
    case SinComprobanteAutorizado = 'sin_comprobante_autorizado';
    case EnRevision = 'en_revision';
    case Corregido = 'corregido';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PendienteComprobante => 'Pendiente de comprobante',
            self::PendienteRendir => 'Pendiente de rendir',
            self::RendidoCompleto => 'Rendido',
            self::SinComprobanteAutorizado => 'Sin comprobante autorizado',
            self::EnRevision => 'En revisión',
            self::Corregido => 'Corregido',
        };
    }
}
