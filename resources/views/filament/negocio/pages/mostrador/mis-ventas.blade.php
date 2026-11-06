<div>
    @if ($sucursalId !== null)
        <livewire:negocio.caja.ventas-de-sesion
            :sucursal-mostrador-id="$sucursalId"
            :key="'mis-ventas-'.$sucursalId"
        />
    @endif
</div>
