@php
    $panel = 'negocio-panel';
@endphp

<div class="min-w-0 space-y-4 overflow-x-hidden p-px max-lg:pb-28">
    <x-negocio.mostrador.banners
        :ultimo-codigo="$ultimoCodigo"
        :ultimo-total="$ultimoTotal"
        :alertas-perdida="$alertasPerdida"
        :moneda="$moneda"
    />

    <x-negocio.mostrador.contexto
        :sucursales="$sucursales"
        :almacenes="$almacenes"
        :cliente-seleccionado="$clienteSeleccionado"
        :lista-precio="$listaPrecio"
        :panel="$panel"
    />
    <div class="grid min-w-0 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_360px] xl:grid-cols-[minmax(0,1fr)_400px]">
        <div class="min-w-0 space-y-4">

            <x-negocio.mostrador.busqueda
                :panel="$panel"
                :busqueda="$busqueda"
                :resultados-busqueda="$resultadosBusqueda"
                :indice-resultado="$indiceResultado"
                :total-sugerencias="$totalSugerencias"
                :moneda="$moneda"
            />

            <x-negocio.mostrador.lineas
                :lineas="$lineas"
                :moneda="$moneda"
                :subtotal-bruto="$subtotalBruto"
                :totales-linea="$totalesLinea"
                :panel="$panel"
            />
        </div>

        <x-negocio.mostrador.resumen-cobro
            :caja-abierta="$cajaAbierta"
            :venta-fiada="$ventaFiada"
            :tiene-cliente="$clienteSeleccionado !== null"
            :moneda="$moneda"
            :subtotal-bruto="$subtotalBruto"
            :descuento-total="$descuentoTotal"
            :total-tentativo="$totalTentativo"
            :total-recibido="$totalRecibido"
            :diferencia-cobros="$diferenciaCobros"
            :lineas="$lineas"
            :pagos="$pagos"
            :metodos="$metodos"
            :tipos-descuento="$tiposDescuento"
            :mostrar-descuento-venta="$mostrarDescuentoVenta"
            :tipo-descuento-venta="$tipoDescuentoVenta"
            :comprobantes-obligatorios="$comprobantesObligatorios"
            :panel="$panel"
        />
    </div>

    <details class="{{ $panel }} space-y-3 p-4">
        <summary class="cursor-pointer font-semibold">Abonar a una venta a crédito</summary>
        <form wire:submit="registrarAbono" class="space-y-3">
            <label class="block text-sm">
                Código de la venta de esta sucursal
                <x-negocio.input wire:model="codigoAbono" placeholder="VNT-000001" />
            </label>
            @error('codigoAbono')
                <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
            @enderror
            <label class="block text-sm">
                Abono en efectivo
                <x-negocio.input-monto :moneda="$moneda" wire:model="montoAbono" inputmode="decimal" />
            </label>
            @error('montoAbono')
                <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
            @enderror
            <x-filament::button type="submit" :disabled="! $cajaAbierta" wire:loading.attr="disabled" wire:target="registrarAbono">
                Registrar abono
            </x-filament::button>
        </form>
    </details>

    <x-negocio.mostrador.busqueda-avanzada
        :mostrar="$mostrarBusquedaAvanzada"
        :busqueda-avanzada="$busquedaAvanzada"
        :categorias="$categorias"
        :atributos-categoria="$atributosCategoria"
        :filtro-categoria-id="$filtroCategoriaId"
        :filtro-disponibilidad="$filtroDisponibilidad"
        :filtros-atributos="$filtrosAtributos"
        :atributos-visibles="$atributosVisibles"
        :resultados-avanzados="$resultadosAvanzados"
        :total-avanzados="$totalAvanzados"
        :pagina-avanzada="$paginaAvanzada"
        :ultimas-paginas-avanzadas="$ultimasPaginasAvanzadas"
        :lista="$busquedaAvanzadaLista"
        :moneda="$moneda"
    />

    <x-negocio.mostrador.eleccion-precio
        :mostrar="$mostrarEleccionPrecio"
        :nombre="$clientePendienteNombre"
        :filas="$comparacionPrecio"
        :total-menor="$totalMenorPendiente"
        :total-mayor="$totalMayorPendiente"
        :antes-de-descuento="$eleccionAntesDeDescuento"
        :moneda="$moneda"
    />

    <x-negocio.mostrador.cliente
        :mostrar="$mostrarCliente"
        :registrando="$mostrandoRegistroCliente"
        :busqueda="$busquedaCliente"
        :resultados="$resultadosCliente"
        :puede-registrar="$puedeRegistrarCliente"
        :tipo="$clienteTipo"
    />

    <x-negocio.mostrador.flash
        :mostrar-flash="$mostrarFlash"
        :flash-variante-id="$flashVarianteId"
        :flash-nombre="$flashNombre"
        :flash-sku="$flashSku"
        :flash-precio-venta="$flashPrecioVenta"
        :flash-costo="$flashCosto"
        :flash-cantidad="$flashCantidad"
        :moneda="$moneda"
    />
</div>
