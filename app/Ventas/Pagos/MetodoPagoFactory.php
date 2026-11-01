<?php

namespace App\Ventas\Pagos;

use App\Enums\MetodoPago;
use App\Ventas\Pagos\Handlers\EfectivoHandler;
use App\Ventas\Pagos\Handlers\QrExternoHandler;
use App\Ventas\Pagos\Handlers\TransferenciaHandler;
use App\Ventas\Pagos\Handlers\VoucherTarjetaHandler;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class MetodoPagoFactory
{
    /**
     * @var array<string, class-string<MetodoPagoHandler>>
     */
    private array $handlers = [
        MetodoPago::Efectivo->value => EfectivoHandler::class,
        MetodoPago::QrExterno->value => QrExternoHandler::class,
        MetodoPago::VoucherTarjeta->value => VoucherTarjetaHandler::class,
        MetodoPago::Transferencia->value => TransferenciaHandler::class,
    ];

    public function make(MetodoPago|string|null $metodo): MetodoPagoHandler
    {
        $valor = $metodo instanceof MetodoPago ? $metodo->value : (is_string($metodo) ? $metodo : null);
        $enum = $valor !== null ? MetodoPago::tryFrom($valor) : null;

        if ($enum === null) {
            throw ValidationException::withMessages([
                'metodo_pago' => 'Elegí un método de pago válido.',
            ]);
        }

        $clase = $this->handlers[$enum->value] ?? null;

        if ($clase === null) {
            throw new InvalidArgumentException("No hay handler para {$enum->value}.");
        }

        return app($clase);
    }

    /**
     * Para agregar un método nuevo: registrá el enum y mapeá acá su handler.
     *
     * @param  class-string<MetodoPagoHandler>  $handler
     */
    public function extend(MetodoPago $metodo, string $handler): void
    {
        $this->handlers[$metodo->value] = $handler;
    }
}
