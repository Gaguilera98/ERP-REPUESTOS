<!doctype html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $message }}</title>
        <style>
            :root { color-scheme: light dark; }
            body {
                display: grid;
                min-height: 100vh;
                margin: 0;
                place-items: center;
                font-family: system-ui, sans-serif;
                text-align: center;
            }
            main { padding: 1.5rem; }
            h1 { margin: 0; font-size: 1.5rem; }
        </style>
    </head>
    <body>
        <main>
            <p>404</p>
            <h1>{{ $message }}</h1>
            <p>Revisá que la dirección del negocio esté escrita correctamente.</p>
        </main>
    </body>
</html>
