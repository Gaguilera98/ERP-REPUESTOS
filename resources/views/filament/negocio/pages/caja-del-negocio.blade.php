<x-filament-panels::page>
    @php
        $moneda = $this->moneda();
        $arqueo = $this->arqueoTurno();
        $sesion = $this->sesionActual();
        $abierta = $sesion !== null;
        $historial = $this->historial();
        $declarados = is_array($this->data['medios_declarados'] ?? null)
            ? $this->data['medios_declarados']
            : [];
        $diferencias = $abierta ? $this->diferenciasPorMedio($declarados) : [];
        $mediosConDif = collect($diferencias)->filter(fn (string $d): bool => $d !== '0.00')->count();
        $justificacion = trim((string) ($this->data['justificacion_diferencia'] ?? ''));
        $cierreBloqueado = $abierta && $mediosConDif > 0 && $justificacion === '';
    @endphp

    <div @class([
        'mx-auto w-full space-y-5',
        'max-w-3xl' => ! $abierta,
        'max-w-5xl' => $abierta,
    ])>
        <form wire:submit="{{ $abierta ? 'cerrar' : 'abrir' }}" class="space-y-5">
            @if ($abierta && $arqueo !== null)
                @include('filament.negocio.pages.caja.turno', [
                    'sesion' => $sesion,
                    'arqueo' => $arqueo,
                    'moneda' => $moneda,
                    'diferencias' => $diferencias,
                    'mediosConDif' => $mediosConDif,
                    'cierreBloqueado' => $cierreBloqueado,
                    'justificacionCompleta' => $mediosConDif > 0 && $justificacion !== '',
                ])
            @else
                @include('filament.negocio.pages.caja.abrir')
            @endif
        </form>

        @include('filament.negocio.pages.caja.historial', [
            'historial' => $historial,
            'moneda' => $moneda,
        ])
    </div>
</x-filament-panels::page>
