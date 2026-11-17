<!doctype html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $titulo }}</title>
        <style>
            @page { size: A4 portrait; margin: 14mm; }
            * { box-sizing: border-box; }
            body { margin: 0; color: #17232c; background: #edf0ee; font: 10.5pt/1.45 Arial, sans-serif; }
            h1, h2, p, dl, dd { margin: 0; }
            h1 { font-size: 22pt; line-height: 1.15; }
            h2 { margin-bottom: 2mm; font-size: 11pt; }
            dt, .secundario { color: #42515a; font-size: 9pt; }
            .hoja, .barra { width: min(calc(100% - 24px), 210mm); margin: auto; }
            .barra { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; padding: 14px 0; }
            .acciones { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
            .acciones button, .acciones a { padding: 9px 12px; border: 1px solid #174c3a; border-radius: 7px; background: #fff; color: #174c3a; font: inherit; text-decoration: none; cursor: pointer; }
            .acciones .compartir { background: #174c3a; color: #fff; }
            .acciones button:disabled { opacity: .65; cursor: wait; }
            .estado-archivo { width: 100%; color: #42515a; }
            .hoja { padding: 14mm; margin-bottom: 24px; background: #fff; box-shadow: 0 3px 24px #17232c19; }
            .encabezado { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 4mm; padding-bottom: 4mm; border-bottom: 2px solid #174c3a; }
            .negocio { color: #174c3a; font-weight: bold; }
            .referencia { display: grid; align-content: start; gap: 1mm; }
            .ficha { display: flex; flex-wrap: wrap; gap: 3mm 7mm; margin: 5mm 0; }
            .ficha > div { min-width: 32mm; }
            .tabla { overflow-x: auto; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 2.3mm 1.7mm; border-bottom: 1px solid #bdc7c2; text-align: left; vertical-align: top; }
            th { background: #edf3ef; font-weight: bold; }
            td:first-child { overflow-wrap: anywhere; }
            .numero { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
            .resumen { width: min(100%, 78mm); margin: 5mm 0 0 auto; break-inside: avoid; }
            .resumen > div { display: flex; justify-content: space-between; gap: 4mm; padding: 1mm 0; }
            .total { border-top: 1px solid #174c3a; font-weight: bold; font-size: 12pt; }
            .aviso { margin-top: 4mm; padding: 2mm 3mm; border-left: 2px solid #174c3a; }
            .pie { margin-top: 6mm; padding-top: 2mm; border-top: 1px solid #bdc7c2; font-size: 9pt; }
            .encabezado, .aviso, .pie, tr { break-inside: avoid; }
            @media screen and (max-width: 600px) {
                .hoja { padding: 5mm; }
                th, td { padding-inline: 1.4mm; }
            }
            @media print {
                body { background: #fff; }
                .barra { display: none; }
                .hoja { width: auto; margin: 0; padding: 0; box-shadow: none; }
                .tabla { overflow: visible; }
                thead { display: table-header-group; }
            }
            @if ($paraPdf)
                body { background: #fff; font-family: 'DejaVu Sans', sans-serif; }
                .hoja { width: auto; margin: 0; padding: 0; box-shadow: none; }
                .encabezado, .ficha, .resumen > div { display: table; width: 100%; }
                .encabezado, .ficha { table-layout: fixed; }
                .encabezado > div, .ficha > div, .resumen dt, .resumen dd { display: table-cell; vertical-align: top; }
                .encabezado > div:first-child { width: 68%; word-wrap: break-word; }
                .encabezado .referencia { text-align: right; white-space: nowrap; }
                .referencia > * { display: block; }
                .ficha > div { padding-right: 3mm; word-wrap: break-word; }
                .tabla { overflow: visible; }
                .resumen { width: 78mm; }
                .encabezado, .aviso, .pie, .resumen { page-break-inside: avoid; }
            @endif
        </style>
    </head>
    <body>
        @unless ($paraPdf)
            <div class="barra">
                <p>Vista previa · A4</p>
                <div class="acciones">
                    <button type="button" onclick="window.print()">Imprimir</button>
                    <a id="descargar-pdf" href="{{ $urlPdf }}" download="{{ $nombreArchivo }}">Descargar PDF</a>
                    <button id="compartir-pdf" class="compartir" type="button" hidden>Compartir PDF</button>
                </div>
                <p id="estado-pdf" class="estado-archivo" role="status" hidden></p>
            </div>
        @endunless
        <main class="hoja">
            @yield('documento')
        </main>
        @unless ($paraPdf)
            <script>
                const descargarPdf = document.getElementById('descargar-pdf')
                const compartirPdf = document.getElementById('compartir-pdf')
                const estadoPdf = document.getElementById('estado-pdf')
                const tipoPdf = 'application/pdf'

                function mostrarEstadoPdf(mensaje) {
                    estadoPdf.textContent = mensaje
                    estadoPdf.hidden = false
                }

                async function prepararArchivoParaCompartir() {
                    const nombre = descargarPdf.download
                    if (typeof navigator.share !== 'function' || typeof navigator.canShare !== 'function') {
                        return
                    }

                    compartirPdf.hidden = false
                    compartirPdf.disabled = true
                    compartirPdf.textContent = 'Preparando PDF…'

                    try {
                        const respuesta = await fetch(descargarPdf.href, { cache: 'no-store', redirect: 'error' })
                        if (! respuesta.ok || respuesta.headers.get('Content-Type')?.split(';')[0] !== tipoPdf) {
                            throw new Error('Respuesta PDF inválida')
                        }

                        const archivo = new File([await respuesta.blob()], nombre, { type: tipoPdf })
                        if (! navigator.canShare({ files: [archivo] })) {
                            compartirPdf.hidden = true
                            return
                        }

                        compartirPdf.addEventListener('click', async () => {
                            estadoPdf.hidden = true

                            try {
                                await navigator.share({ files: [archivo] })
                            } catch (error) {
                                if (error.name !== 'AbortError') {
                                    mostrarEstadoPdf('No se pudo compartir. Podés descargar el PDF y adjuntarlo.')
                                }
                            }
                        })
                        compartirPdf.textContent = 'Compartir PDF'
                        compartirPdf.disabled = false
                    } catch {
                        compartirPdf.hidden = true
                        mostrarEstadoPdf('No se pudo preparar el archivo para compartir. Probá con Descargar PDF.')
                    }
                }

                prepararArchivoParaCompartir()
            </script>
        @endunless
    </body>
</html>
