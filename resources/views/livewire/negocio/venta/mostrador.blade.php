@php
    $panel = 'negocio-panel';
@endphp

<div class="min-w-0 space-y-4 overflow-x-hidden max-lg:pb-28">
    <x-negocio.mostrador.banners
        :ultimo-codigo="$ultimoCodigo"
        :ultimo-total="$ultimoTotal"
        :alertas-perdida="$alertasPerdida"
        :moneda="$moneda"
    />

    <div class="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_360px] xl:grid-cols-[minmax(0,1fr)_400px]">
        <div class="min-w-0 space-y-4">
            <x-negocio.mostrador.contexto
                :sucursales="$sucursales"
                :almacenes="$almacenes"
                :cliente-seleccionado="$clienteSeleccionado"
                :panel="$panel"
            />

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
            :panel="$panel"
        />
    </div>

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
    />
</div>
