@extends('filament.negocio.documentos.layout-a4')

@use('App\Compras\Montos')
@use('App\Enums\EstadoVenta')
@use('App\Ventas\NombreEnPantalla')

@section('documento')
    <header class="encabezado">
        <div>
            <p class="negocio">{{ $negocio }}</p>
            <h1>Nota de venta</h1>
        </div>
        <div class="referencia">
            <strong>{{ $venta->codigo }}</strong>
            <span>{{ $venta->created_at?->format('d/m/Y H:i') }}</span>
            <span>{{ $venta->estado->etiqueta() }}</span>
        </div>
    </header>

    <dl class="ficha">
        <div><dt>Cliente</dt><dd>{{ $venta->cliente?->nombre_mostrar ?? 'Consumidor final' }}</dd></div>
        <div><dt>Sucursal</dt><dd>{{ $venta->sucursal?->nombre ?? '—' }}</dd></div>
        <div><dt>Vendedor</dt><dd>{{ $venta->usuario?->name ?? '—' }}</dd></div>
        <div><dt>Condición</dt><dd>{{ $venta->condicion->etiqueta() }}</dd></div>
    </dl>

    <section aria-labelledby="productos">
        <h2 id="productos">{{ $hayCambios ? 'Productos de la venta original' : 'Productos' }}</h2>
        <div class="tabla">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Producto</th>
                        <th scope="col" class="numero">Cantidad</th>
                        <th scope="col" class="numero">Precio unitario</th>
                        <th scope="col" class="numero">Descuento</th>
                        <th scope="col" class="numero">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($venta->detalles as $detalle)
                        @php($unidadBase = $detalle->variante?->productoMaestro?->unidadBase?->simbolo ?? $detalle->unidad?->simbolo)
                        <tr>
                            <td>
                                <strong>{{ NombreEnPantalla::titulo($detalle->variante?->productoMaestro?->nombre) }}</strong>
                                @if ($detalle->variante?->sku)
                                    <p class="secundario">{{ $detalle->variante->sku }}</p>
                                @endif
                            </td>
                            <td class="numero">
                                {{ Montos::sinCeros($detalle->cantidad) }} {{ $detalle->unidad?->simbolo }}
                                @if (bccomp($detalle->factor_a_base, '1', 6) !== 0)
                                    <p class="secundario">{{ Montos::sinCeros($detalle->cantidad_base) }} {{ $unidadBase }}</p>
                                @endif
                            </td>
                            <td class="numero">
                                {{ $moneda }} {{ $detalle->precio_unitario }}
                                @if ($unidadBase)
                                    <p class="secundario">por {{ $unidadBase }}</p>
                                @endif
                            </td>
                            <td class="numero">{{ $moneda }} {{ $detalle->descuento_aplicado }}</td>
                            <td class="numero">{{ $moneda }} {{ $detalle->total_linea }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No hay productos registrados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <dl class="resumen">
        <div><dt>Subtotal</dt><dd class="numero">{{ $moneda }} {{ $venta->subtotal }}</dd></div>
        <div><dt>Descuentos</dt><dd class="numero">{{ $moneda }} {{ $venta->descuento }}</dd></div>
        <div class="total">
            <dt>{{ $hayCambios || $venta->estado === EstadoVenta::Anulada ? 'Total original' : 'Total de la venta' }}</dt>
            <dd class="numero">{{ $moneda }} {{ $venta->total }}</dd>
        </div>
        @if ($totalVigente !== null)
            <div><dt>Total vigente</dt><dd class="numero">{{ $moneda }} {{ $totalVigente }}</dd></div>
        @endif
        @if ($saldo !== null)
            <div><dt>Pagado a la fecha</dt><dd class="numero">{{ $moneda }} {{ $pagado }}</dd></div>
            <div><dt>Saldo pendiente</dt><dd class="numero">{{ $moneda }} {{ $saldo }}</dd></div>
        @endif
    </dl>

    @if ($venta->estado === EstadoVenta::Anulada)
        <p class="aviso"><strong>Venta anulada.</strong> Los importes corresponden al registro original.</p>
    @elseif ($hayCambios)
        <p class="aviso">Esta venta tiene movimientos posteriores. La tabla conserva el detalle original.</p>
    @endif

    @if ($cobros->isNotEmpty())
        <dl class="resumen">
            @foreach ($cobros as $aplicacion)
                <div><dt>Efectivo recibido</dt><dd class="numero">{{ $moneda }} {{ $aplicacion->pago->efectivo_recibido }}</dd></div>
                @if (bccomp($aplicacion->pago->cambio_entregado ?? '0', '0', 2) > 0)
                    <div><dt>Vuelto entregado</dt><dd class="numero">{{ $moneda }} {{ $aplicacion->pago->cambio_entregado }}</dd></div>
                @endif
            @endforeach
        </dl>
    @endif

    <footer class="pie">DOCUMENTO INTERNO DE CONTROL - NO TIENE VALIDEZ FISCAL NI SIRVE PARA CRÉDITO FISCAL</footer>
@endsection
