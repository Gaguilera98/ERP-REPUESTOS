<?php

namespace App\Catalogo;

use App\Enums\UsoUnidad;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Proveedor;
use App\Models\UnidadMedida;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;
use ZipArchive;

class ArchivoProductos
{
    public const MAX_FILAS = 1000;

    public const COLUMNAS = [
        'sku', 'nombre', 'categoria', 'marca', 'unidad_stock', 'codigo_barras',
        'precio_menor', 'precio_mayor', 'cantidad_inicial', 'costo_unitario', 'proveedor', 'atributos',
    ];

    /** @param list<string|int> $valores */
    public static function filaDeTexto(array $valores): Row
    {
        return new Row(array_map(fn (string|int $valor): StringCell => new StringCell((string) $valor, null), $valores));
    }

    /** @return list<array<string, string|int>> */
    public function leer(string $ruta): array
    {
        $zip = new ZipArchive;
        if ($zip->open($ruta) !== true) {
            throw ValidationException::withMessages(['archivo' => 'Subí un archivo Excel .xlsx válido.']);
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
        $filas = [];
        $errores = [];
        try {
            $reader->open($ruta);
            foreach ($reader->getSheetIterator() as $sheet) {
                $cabecera = null;
                foreach ($sheet->getRowIterator() as $numero => $row) {
                    foreach ($row->getCells() as $cell) {
                        if ($cell instanceof FormulaCell) {
                            throw ValidationException::withMessages(['archivo' => 'Fila '.$numero.': pegá los valores de las fórmulas antes de subir el archivo.']);
                        }
                    }
                    $valores = array_map(function (mixed $valor): string {
                        return is_float($valor) ? rtrim(rtrim(number_format($valor, 8, '.', ''), '0'), '.') : (is_scalar($valor) ? trim((string) $valor) : '');
                    }, $row->toArray());
                    if ($cabecera === null) {
                        $cabecera = $valores;
                        if ($cabecera !== self::COLUMNAS) {
                            throw ValidationException::withMessages(['archivo' => 'Usá las columnas de nuestra plantilla, en el mismo orden y en la primera hoja.']);
                        }

                        continue;
                    }
                    if (count(array_filter($valores, fn (string $valor): bool => $valor !== '')) === 0) {
                        continue;
                    }
                    if (count($valores) > count(self::COLUMNAS)) {
                        throw ValidationException::withMessages(['archivo' => 'Fila '.$numero.': hay columnas adicionales fuera de la plantilla.']);
                    }
                    if (count($filas) >= self::MAX_FILAS) {
                        throw ValidationException::withMessages(['archivo' => 'Cada archivo admite hasta '.self::MAX_FILAS.' filas.']);
                    }
                    $filas[] = ['fila' => $numero, ...array_combine(self::COLUMNAS, array_pad($valores, count(self::COLUMNAS), ''))];
                }
                break;
            }
        } catch (ValidationException $exception) {
            $errores = $exception->errors();
            unset($exception);
        } catch (Throwable $exception) {
            report($exception);
            $errores = ['archivo' => 'No pudimos leer el Excel. Descargá la plantilla y guardalo como .xlsx.'];
            unset($exception);
        } finally {
            $reader->close();
            unset($sheet, $reader);
            gc_collect_cycles();
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
        if ($filas === []) {
            throw ValidationException::withMessages(['archivo' => 'El archivo está vacío. Completá al menos un producto.']);
        }

        return $filas;
    }

    public function plantilla(string $ruta, string $moneda): void
    {
        $writer = new Writer;
        $writer->openToFile($ruta);
        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Productos');
            $sheet->setColumnWidth(24, ...range(1, count(self::COLUMNAS)));
            $writer->addRow(Row::fromValues(self::COLUMNAS, (new Style)->setFontBold()->setBackgroundColor('DDECE5')));
            $texto = (new Style)->setFormat('@');
            for ($fila = 0; $fila < self::MAX_FILAS; $fila++) {
                $writer->addRow(Row::fromValuesWithStyles(array_fill(0, count(self::COLUMNAS), ''), columnStyles: [0 => $texto, 5 => $texto]));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Instrucciones y ejemplo');
            foreach ([
                ['Completá la primera hoja. El ejemplo de esta hoja no se importa.'],
                ['Hasta '.self::MAX_FILAS.' filas. Mantené los encabezados y su orden.'],
                ['SKU y código de barras', 'Usá formato Texto antes de escribir para conservar ceros iniciales.'],
                ['Precios y costos', 'Importes en '.$moneda.'. Usá punto o coma decimal, sin separador de miles.'],
                ['Catálogo', 'Nombre, categoría, unidad_stock y precio_menor son obligatorios para un SKU nuevo.'],
                ['Existentes', 'Para actualizar, marcá la opción en la pantalla. Las celdas opcionales vacías conservan sus valores.'],
                ['Inventario inicial', 'Elegí el almacén en la pantalla. Cantidad y costo se expresan por unidad de stock.'],
                ['Lotes', 'Repetí el SKU con iguales datos del catálogo y distintos costos o proveedores para registrar varios lotes.'],
                ['Proveedor', 'Opcional. Escribí el nombre exacto del proveedor del negocio.'],
                ['Atributos', 'Opcional. JSON con claves de la ficha técnica, por ejemplo {"diametro":5}.'],
                ['Solo catálogo', 'Dejá vacías cantidad_inicial, costo_unitario y proveedor.'],
                self::COLUMNAS,
                ['0006206', 'Rodamiento 6206', 'Rodamientos', '', 'pza', '001234567890', '45', '40', '20', '28', '', ''],
            ] as $valores) {
                $writer->addRow(self::filaDeTexto($valores));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Catálogos del negocio');
            $writer->addRow(Row::fromValues(['Tipo', 'Valor para copiar']));
            foreach ([
                'Categoría' => Categoria::query()->where('is_active', true)->orderBy('nombre')->pluck('nombre'),
                'Marca' => Marca::query()->orderBy('nombre')->pluck('nombre'),
                'Unidad de stock' => UnidadMedida::query()->where('uso', UsoUnidad::Cantidad)->orderBy('simbolo')->pluck('simbolo'),
                'Proveedor' => Proveedor::query()->orderBy('nombre_empresa')->pluck('nombre_empresa'),
            ] as $tipo => $valores) {
                foreach ($valores as $valor) {
                    $writer->addRow(self::filaDeTexto([$tipo, $valor]));
                }
            }
        } finally {
            $writer->close();
        }
    }
}
