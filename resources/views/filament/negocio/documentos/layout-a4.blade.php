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
            .barra button { padding: 9px 15px; border: 0; border-radius: 7px; background: #174c3a; color: #fff; font: inherit; cursor: pointer; }
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
        </style>
    </head>
    <body>
        <div class="barra">
            <p>Vista previa · A4</p>
            <button type="button" onclick="window.print()">Imprimir o guardar como PDF</button>
        </div>
        <main class="hoja">
            @yield('documento')
        </main>
    </body>
</html>
