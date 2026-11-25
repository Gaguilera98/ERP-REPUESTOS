<?php

namespace App\Catalogo;

use App\Enums\MagnitudUnidad;
use App\Enums\UsoUnidad;
use App\Models\UnidadMedida;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
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
            foreach (self::HOJAS as $nombre => $columnas) {
                $sheet = $nombre === 'Unidades' ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $sheet->setName($nombre);
                $sheet->setColumnWidth(26, ...range(1, count($columnas)));
                $writer->addRow(ArchivoProductos::filaDeTexto($columnas));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Guia para usuario e IA');
            foreach ([
                ['Objetivo', 'Prepará categorías, atributos y unidades. No incluyas productos, precios ni stock. Solo se importan Unidades, Categorias y Atributos.'],
                ['Límites', 'Excel .xlsx de hasta 5 MB y 500 filas de datos por hoja. Conservá encabezados. Pegá valores, no fórmulas.'],
                ['Códigos', 'Referencias internas del archivo, no IDs de la base de datos. Usá códigos únicos como U_MM y CAT_ROD y mantené las referencias entre hojas.'],
                ['Unidades', 'Incluí las unidades a reutilizar y las nuevas. Atributos.unidad referencia Unidades.codigo. Podrás seleccionar una unidad existente compatible en la revisión.'],
                ['Uso', implode(' | ', array_column(UsoUnidad::cases(), 'value')).'. atributo: ficha técnica; cantidad: compra, stock y venta. Son usos diferentes.'],
                ['Magnitud', implode(' | ', array_column(MagnitudUnidad::cases(), 'value'))],
                ['Unidades de atributo', 'Dejá vacíos factor_a_referencia, decimales y es_referencia. No convierten cantidades de inventario.'],
                ['Unidades de cantidad', 'Completá factor_a_referencia positivo con hasta 6 decimales, decimales entre 0 y 4, es_referencia si/no. Una referencia usa factor 1. No inventes equivalencias: verificá la referencia del negocio.'],
                ['Categorías', 'codigo y nombre obligatorios. Las existentes se reutilizan sin cambiar su estado ni política comercial. No se buscan nombres parecidos.'],
                ['Atributos', 'categoria referencia Categorias.codigo. etiqueta es visible; clave es estable, sin unidad, en minúsculas con guiones bajos: diametro_interno.'],
                ['Tipos y unidades', 'decimal | texto | lista. Solo decimal admite unidad, opcional si no tiene escala. Conteo de stock, como pieza, no es una unidad de ficha.'],
                ['Obligatorio', 'si o no. Exigí solo lo necesario. No reemplaces datos desconocidos por cero.'],
                ['Opciones', 'Solo lista: opciones únicas separadas por |, por ejemplo 2RS|ZZ|ABIERTO. Texto y decimal dejan opciones vacías.'],
                ['Revisión', 'Subir no crea registros. Revisá Crear, Reutilizar y conflictos. No se sobrescriben unidades ni atributos existentes. Se aplica todo junto o nada.'],
                ['Ejemplo Unidades', 'U_MM | Milímetro | mm | atributo | longitud | (vacío) | (vacío) | (vacío)'],
                ['Ejemplo Categorias', 'CAT_ROD | Rodamientos'],
                ['Ejemplo Atributos', 'CAT_ROD | Diámetro interno | diametro_interno | decimal | U_MM | si | (vacío)'],
                ['Ejemplo Atributos', 'CAT_ROD | Sello | sello | lista | (vacío) | no | 2RS|ZZ|ABIERTO'],
                ['Para la IA', 'Entregá las tres hojas con encabezados exactos. Consultá Unidades existentes para reutilizarlas. Pedí información faltante al usuario: no adivines magnitudes, usos ni factores. Las instrucciones no son filas de datos.'],
            ] as $fila) {
                $writer->addRow(ArchivoProductos::filaDeTexto($fila));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Unidades existentes');
            $writer->addRow(ArchivoProductos::filaDeTexto(self::HOJAS['Unidades']));
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
