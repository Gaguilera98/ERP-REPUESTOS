<div class="space-y-4">
    @if ($vale)
        <div>
            <p class="text-xs text-gray-500 dark:text-gray-400">Vale #{{ $vale->id }} · {{ $vale->sucursal?->nombre }}</p>
            <p class="mt-1 font-semibold">{{ $vale->concepto }}</p>
        </div>
        <dl class="grid gap-3 rounded-xl bg-gray-50 p-4 text-sm dark:bg-white/5 sm:grid-cols-3">
            <div><dt>Entregado</dt><dd class="font-semibold">{{ $moneda }} {{ $vale->monto }}</dd></div>
            <div><dt>Gastado</dt><dd class="font-semibold">{{ $vale->monto_gasto === null ? '—' : $moneda.' '.$vale->monto_gasto }}</dd></div>
            <div><dt>Devuelto</dt><dd class="font-semibold">{{ $vale->monto_sobrante === null ? '—' : $moneda.' '.$vale->monto_sobrante }}</dd></div>
        </dl>
        <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
            <li class="py-2">
                <p class="font-medium">Vale entregado a {{ $vale->persona_recibe }}</p>
                <p class="text-gray-500 dark:text-gray-400">{{ $vale->created_at->format('d/m/Y H:i') }} · Registrado por {{ $vale->registradoPor?->name }}</p>
            </li>
            <li class="py-2">
                <p class="font-medium">{{ $vale->estado->etiqueta() }}</p>
                @if ($vale->rendidoPor)
                    <p class="text-gray-500 dark:text-gray-400">Rendido por {{ $vale->rendidoPor->name }}</p>
                @endif
                @if ($sobrante)
                    <p class="text-gray-500 dark:text-gray-400">Sobrante ingresado el {{ $sobrante->created_at->format('d/m/Y H:i') }}</p>
                @endif
            </li>
        </ul>
        @if ($vale->tipo_comprobante || $vale->numero_comprobante || $vale->comprobante_archivo_path)
            <p class="text-sm">
                <strong>Comprobante</strong>
                {{ $vale->tipo_comprobante?->etiqueta() }} {{ $vale->numero_comprobante }}
                @if ($vale->comprobante_archivo_path) · Archivo adjunto @endif
            </p>
        @endif
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">El detalle de este vale no está disponible para tu usuario.</p>
    @endif
</div>
