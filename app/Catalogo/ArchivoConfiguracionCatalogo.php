<?php

namespace App\Catalogo;

use App\Enums\MagnitudUnidad;
use App\Enums\UsoUnidad;
use App\Models\UnidadMedida;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;
use ZipArchive;

class ArchivoConfiguracionCatalogo
{
    public const MAX_FILAS = 500;

    public const HOJAS = [
        'Unidades' => ['codigo', 'nombre', 'simbolo', 'uso', 'magnitud', 'factor_a_referencia', 'decimales', 'es_referencia'],
        'Categorias' => ['codigo', 'nombre'],
        'Atributos' => ['categoria', 'etiqueta', 'clave', 'tipo', 'unidad', 'obligatorio', 'opciones'],
    ];

    public function plantilla(string $ruta): void
    {
        $writer = new Writer;
        $writer->openToFile($ruta);
        try {
            $cabecera = (new Style)->setFontName('Arial')->setFontSize(10)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('166534')->setShouldWrapText()->setCellVerticalAlignment('center');
            $texto = (new Style)->setFontName('Arial')->setFontSize(10)->setShouldWrapText()->setCellVerticalAlignment('center');
            $guia = $writer->getCurrentSheet();
            $guia->setName('Empezar aqui');
            $guia->setColumnWidth(30, 1);
            $guia->setColumnWidth(95, 2);
            $writer->addRow(ArchivoProductos::filaDeTexto(['Prepará tus categorías', 'Leé estos pasos antes de completar las otras hojas.'])->setStyle($cabecera)->setHeight(32));
            foreach ($this->instruccionesPlantilla() as $fila) {
                $writer->addRow(ArchivoProductos::filaDeTexto($fila)->setStyle($texto)->setHeight(60));
            }
            foreach (['Categorias', 'Atributos', 'Unidades'] as $nombre) {
                $columnas = self::HOJAS[$nombre];
                $sheet = $writer->addNewSheetAndMakeItCurrent();
                $sheet->setName($nombre);
                $sheet->setColumnWidth(26, ...range(1, count($columnas)));
                $writer->addRow(ArchivoProductos::filaDeTexto($columnas)->setStyle($cabecera)->setHeight(32));
            }
            $ejemplo = $writer->addNewSheetAndMakeItCurrent();
            $ejemplo->setName('Ejemplo Rodamientos');
            $ejemplo->setColumnWidth(27, ...range(1, 8));
            $writer->addRow(ArchivoProductos::filaDeTexto(['EJEMPLO: NO SE IMPORTA', 'Copiá solo las filas de datos a la hoja indicada si querés usar este ejemplo. Las celdas vacías se dejan vacías.'])->setStyle($texto)->setHeight(80));
            foreach ([
                'Categorias' => [['CAT_ROD', 'Rodamientos']],
                'Unidades' => [['U_MM', 'Milímetro', 'mm', 'atributo', 'longitud', '', '', '']],
                'Atributos' => [
                    ['CAT_ROD', 'Diámetro interno', 'diametro_interno', 'decimal', 'U_MM', 'si', ''],
                    ['CAT_ROD', 'Diámetro externo', 'diametro_externo', 'decimal', 'U_MM', 'no', ''],
                    ['CAT_ROD', 'Sello', 'sello', 'lista', '', 'no', '2RS|ZZ|ABIERTO'],
                ],
            ] as $nombre => $filas) {
                $writer->addRow(ArchivoProductos::filaDeTexto(['Copiar a '.$nombre])->setHeight(28));
                $writer->addRow(ArchivoProductos::filaDeTexto(self::HOJAS[$nombre])->setStyle($cabecera)->setHeight(32));
                foreach ($filas as $fila) {
                    $writer->addRow(ArchivoProductos::filaDeTexto($fila)->setStyle($texto)->setHeight(36));
                }
                $writer->addRow(ArchivoProductos::filaDeTexto(['']));
            }
            $existentes = $writer->addNewSheetAndMakeItCurrent();
            $existentes->setName('Unidades existentes');
            $existentes->setColumnWidth(26, ...range(1, 8));
            $writer->addRow(ArchivoProductos::filaDeTexto(self::HOJAS['Unidades'])->setStyle($cabecera)->setHeight(32));
            foreach (UnidadMedida::query()->orderBy('nombre')->get() as $indice => $unidad) {
                $cantidad = $unidad->uso === UsoUnidad::Cantidad;
                $writer->addRow(ArchivoProductos::filaDeTexto([
                    'REF_'.($indice + 1), $unidad->nombre, $unidad->simbolo, $unidad->uso->value, $unidad->magnitud->value,
                    $cantidad ? $unidad->factor_a_referencia : '', $cantidad ? $unidad->decimales : '', $cantidad ? ($unidad->es_referencia ? 'si' : 'no') : '',
                ]));
            }
        } finally {
            $writer->close();
        }
    }

    /** @return list<array{string, string}> */
    private function instruccionesPlantilla(): array
    {
        return [
            ['¿Para qué sirve?', 'Para crear categorías como Rodamientos y decidir qué datos pedirán sus productos, como diámetro o sello. No carga productos, precios ni stock.'],
            ['La forma más sencilla', 'Si solo necesitás nombres de categorías, completá Categorias desde la fila 2. Dejá Atributos y Unidades con sus encabezados. Ya podés guardar y subir el archivo.'],
            ['1. Escribí tus categorías', 'En Categorias, elegí un codigo corto, por ejemplo CAT_ROD, y un nombre, por ejemplo Rodamientos. El código sirve para relacionar las hojas de este Excel; no es un SKU ni un ID del sistema.'],
            ['2. ¿Necesitás más datos?', 'Opcional: en Atributos, agregá una fila por cada dato que querés pedir. Ejemplo: Diámetro interno y Sello son dos filas de la categoría CAT_ROD.'],
            ['3. ¿El dato tiene unidad?', 'Solo si hace falta: en Unidades, declarala una vez. Para un diámetro: U_MM, Milímetro, mm, atributo, longitud. En Atributos.unidad escribí U_MM.'],
            ['4. Revisá el ejemplo', 'La hoja Ejemplo Rodamientos muestra las filas completas en sus columnas. Es solo una referencia: no crea categorías al subir. Copiá únicamente los datos que quieras usar a las hojas de carga.'],
            ['5. Guardá y subí', 'Guardá como .xlsx. En el sistema, elegí Revisar archivo. Revisá qué se creará, qué se reutilizará y los errores. Solo Confirmar configuración crea los registros.'],
            ['Después de importar', 'Podrás descargar la plantilla de productos eligiendo la categoría creada. Esa plantilla incluirá sus campos, como diámetro y sello, para completar cada producto.'],
            ['Qué hojas se cargan', 'Solo Categorias, Atributos y Unidades. No cambies esos nombres ni sus encabezados. Escribí tus datos desde la fila 2, sin títulos adicionales.'],
            ['Campos de Categorias', 'codigo: referencia única dentro de este archivo. nombre: nombre que verá el usuario. Los dos son obligatorios. Ejemplo: CAT_ROD y Rodamientos.'],
            ['Atributos: categoria', 'Copiá el codigo de la categoría a la que pertenece el dato. Ejemplo: CAT_ROD. No escribas aquí el nombre Rodamientos.'],
            ['Atributos: etiqueta y clave', 'etiqueta: nombre visible, por ejemplo Diámetro interno. clave: identificador estable sin espacios ni unidad, por ejemplo diametro_interno. Usá minúsculas y guiones bajos.'],
            ['Atributos: tipo', 'decimal: un número, como un diámetro. texto: una descripción libre. lista: opciones entre las que elegirá el usuario, como ZZ o 2RS. Escribí exactamente decimal, texto o lista.'],
            ['Atributos: unidad', 'Solo para tipo decimal. Copiá el codigo declarado en Unidades, por ejemplo U_MM. Dejá vacío si el dato no tiene unidad. Para texto y lista siempre se deja vacío.'],
            ['Atributos: obligatorio', 'Escribí si cuando el producto deba tener ese dato para guardarse; no si puede dejarse vacío. No vuelvas obligatorio un dato que normalmente desconocés.'],
            ['Atributos: opciones', 'Solo para tipo lista. Separá los valores con |, por ejemplo 2RS|ZZ|ABIERTO. No repitas opciones. Para texto y decimal dejá esta celda vacía.'],
            ['Reutilizar una unidad', 'Consultá Unidades existentes. Copiá su fila completa a Unidades y usá su codigo en Atributos.unidad. En la revisión podrás elegir una unidad existente compatible.'],
            ['Unidades: codigo y nombre', 'codigo: referencia única en este archivo, por ejemplo U_MM. nombre: Milímetro. simbolo: mm. Escribí cada valor en su columna.'],
            ['Unidades: uso', 'atributo: describe el producto, como un diámetro en mm. cantidad: cuenta lo comprado, almacenado o vendido, como piezas o metros. Aunque tengan el mismo símbolo, son usos distintos.'],
            ['Unidades: magnitud', 'Indica qué se mide. Valores admitidos: '.implode(', ', array_column(MagnitudUnidad::cases(), 'value')).'. Para un diámetro usá longitud.'],
            ['Unidades para atributos', 'Con uso atributo, dejá vacíos factor_a_referencia, decimales y es_referencia. Un diámetro no convierte el stock. Pieza es una unidad de cantidad, no de ficha técnica.'],
            ['Unidades para stock', 'Con uso cantidad, completá factor_a_referencia positivo (hasta 6 decimales), decimales (0 a 4) y es_referencia (si/no). Consultá las existentes antes de definir equivalencias. Una referencia usa factor 1.'],
            ['Si ya existe en el negocio', 'La revisión puede reutilizar categorías y unidades. No se sobrescriben sus datos ni los atributos existentes. Una definición incompatible se muestra como conflicto.'],
            ['Antes de subir', 'Hasta 500 filas por hoja y 5 MB. Pegá valores, no fórmulas. No reemplaces datos desconocidos por cero. Las celdas que se indican vacías deben quedar realmente vacías.'],
            ['Ayuda opcional de una IA', 'Podés pedir: Completá esta plantilla para mi negocio. Preguntame qué categorías y campos necesito. Conservá las tres hojas de carga y sus encabezados. Reutilizá Unidades existentes y no inventes unidades, factores ni datos desconocidos.'],
        ];
    }

    /** @return array<string, list<array<string, string|int>>> */
    public function leer(string $ruta): array
    {
        $zip = new ZipArchive;
        if (filesize($ruta) > 5 * 1024 * 1024 || $zip->open($ruta) !== true) {
            throw ValidationException::withMessages(['archivo' => 'Subí un Excel .xlsx válido de hasta 5 MB.']);
        }
        $peso = 0;
        for ($indice = 0; $indice < $zip->numFiles; $indice++) {
            $peso += $zip->statIndex($indice)['size'];
        }
        $zip->close();
        if ($peso > 30 * 1024 * 1024) {
            throw ValidationException::withMessages(['archivo' => 'El contenido del Excel supera el tamaño admitido.']);
        }
        $reader = new Reader;
        $datos = [];
        $errores = [];
        try {
            $reader->open($ruta);
            foreach ($reader->getSheetIterator() as $sheet) {
                $nombre = collect(array_keys(self::HOJAS))->first(fn (string $nombre): bool => Str::lower(Str::ascii($nombre)) === Str::lower(Str::ascii($sheet->getName())));
                if ($nombre === null) {
                    continue;
                }
                if (isset($datos[$nombre])) {
                    throw ValidationException::withMessages(['archivo' => 'La hoja '.$nombre.' está repetida.']);
                }
                $datos[$nombre] = [];
                $columnas = null;
                foreach ($sheet->getRowIterator() as $numero => $row) {
                    foreach ($row->getCells() as $cell) {
                        if ($cell instanceof FormulaCell) {
                            throw ValidationException::withMessages(['archivo' => $nombre.', fila '.$numero.': pegá valores en lugar de fórmulas.']);
                        }
                    }
                    $valores = array_map(fn (mixed $valor): string => is_scalar($valor) ? trim((string) $valor) : '', $row->toArray());
                    if ($columnas === null) {
                        if (count($valores) !== count(self::HOJAS[$nombre]) || array_diff($valores, self::HOJAS[$nombre]) !== [] || count(array_unique($valores)) !== count($valores)) {
                            throw ValidationException::withMessages(['archivo' => 'Revisá los encabezados de '.$nombre.': '.implode(', ', self::HOJAS[$nombre]).'.']);
                        }
                        $columnas = $valores;

                        continue;
                    }
                    if (count(array_filter($valores, fn (string $valor): bool => $valor !== '')) === 0) {
                        continue;
                    }
                    if (count($valores) > count($columnas) || count($datos[$nombre]) >= self::MAX_FILAS) {
                        throw ValidationException::withMessages(['archivo' => $nombre.', fila '.$numero.': no agregues columnas sin encabezado ni superes las '.self::MAX_FILAS.' filas.']);
                    }
                    $datos[$nombre][] = ['_fila' => $numero, ...array_combine($columnas, array_pad($valores, count($columnas), ''))];
                }
                if ($columnas === null) {
                    throw ValidationException::withMessages(['archivo' => 'La hoja '.$nombre.' debe conservar sus encabezados.']);
                }
            }
        } catch (ValidationException $exception) {
            $errores = $exception->errors();
            unset($exception);
        } catch (Throwable $exception) {
            report($exception);
            $errores = ['archivo' => 'No pudimos leer el Excel. Usá la plantilla y guardalo como .xlsx.'];
            unset($exception);
        } finally {
            $reader->close();
            unset($sheet, $row, $reader);
            gc_collect_cycles();
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
        if (array_diff(array_keys(self::HOJAS), array_keys($datos)) !== [] || $datos['Categorias'] === []) {
            throw ValidationException::withMessages(['archivo' => 'Incluí Unidades, Categorias y Atributos, con al menos una categoría. Unidades y Atributos pueden tener solo sus encabezados.']);
        }

        return $datos;
    }
}
