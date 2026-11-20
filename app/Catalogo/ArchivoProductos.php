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
        $archivo = $this->inspeccionar($ruta);

        return app(MapearColumnasProductos::class)->aplicar($archivo, app(MapearColumnasProductos::class)->sugerir($archivo['columnas']));
    }

    /** @return array{columnas: list<string>, filas: list<array{fila: int, valores: list<string>}>} */
    public function inspeccionar(string $ruta): array
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
        $cabecera = null;
        $errores = [];
        try {
            $reader->open($ruta);
            foreach ($reader->getSheetIterator() as $sheet) {
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
                        if (count($cabecera) > 100 || in_array('', $cabecera, true) || count(array_unique($cabecera)) !== count($cabecera)) {
                            throw ValidationException::withMessages(['archivo' => 'La primera fila debe tener hasta 100 encabezados distintos, sin columnas vacías.']);
                        }

                        continue;
                    }
                    if (count(array_filter($valores, fn (string $valor): bool => $valor !== '')) === 0) {
                        continue;
                    }
                    if (count($valores) > count($cabecera)) {
                        throw ValidationException::withMessages(['archivo' => 'Fila '.$numero.': hay valores en columnas sin encabezado.']);
                    }
                    if (count($filas) >= self::MAX_FILAS) {
                        throw ValidationException::withMessages(['archivo' => 'Cada archivo admite hasta '.self::MAX_FILAS.' filas.']);
                    }
                    $filas[] = ['fila' => $numero, 'valores' => array_pad($valores, count($cabecera), '')];
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

        return ['columnas' => $cabecera, 'filas' => $filas];
    }

    public function plantilla(string $ruta, string $moneda, array $categorias = [], ?array $campos = null): void
    {
        $atributos = array_filter(app(MapearColumnasProductos::class)->atributos(), fn (array $campo): bool => array_intersect(array_keys($campo['categorias']), $categorias) !== []);
        $base = array_values(array_diff(self::COLUMNAS, ['atributos']));
        $columnas = [...($campos === null ? $base : array_values(array_unique(['sku', ...array_intersect($base, $campos)]))), ...array_column($atributos, 'label')];
        if (count($columnas) > 100) {
            throw ValidationException::withMessages(['categorias' => 'Elegí menos categorías para mantener la plantilla dentro de 100 columnas.']);
        }
        $writer = new Writer;
        $writer->openToFile($ruta);
        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Productos');
            $sheet->setColumnWidth(24, ...range(1, count($columnas)));
            $writer->addRow(self::filaDeTexto($columnas));
            $texto = (new Style)->setFormat('@');
            $estilos = array_fill_keys(array_keys(array_filter($columnas, fn (string $columna): bool => in_array($columna, ['sku', 'codigo_barras'], true))), $texto);
            for ($fila = 0; $fila < self::MAX_FILAS; $fila++) {
                $writer->addRow(Row::fromValuesWithStyles(array_fill(0, count($columnas), ''), columnStyles: $estilos));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Instrucciones y ejemplo');
            $ejemplo = array_combine(self::COLUMNAS, ['0006206', 'Rodamiento 6206', 'Rodamientos', '', 'pza', '001234567890', '45', '40', '20', '28', '', '']);
            foreach ([
                ['Completá la primera hoja. El ejemplo de esta hoja no se importa.'],
                ['Hasta '.self::MAX_FILAS.' filas. Podés usar otro orden o relacionar columnas de tu propio archivo en la pantalla.'],
                ['SKU y código de barras', 'Usá formato Texto antes de escribir para conservar ceros iniciales.'],
                ['Precios y costos', 'Importes en '.$moneda.'. Usá punto o coma decimal, sin separador de miles.'],
                ['Catálogo', 'Nombre, categoría, unidad_stock y precio_menor son obligatorios para un SKU nuevo.'],
                ['Existentes', 'Para actualizar, marcá la opción en la pantalla. Las celdas opcionales vacías conservan sus valores.'],
                ['Inventario inicial', 'Elegí el almacén en la pantalla. Cantidad y costo se expresan por unidad de stock.'],
                ['Lotes', 'Repetí el SKU con iguales datos del catálogo y distintos costos o proveedores para registrar varios lotes.'],
                ['Proveedor', 'Opcional. Escribí el nombre exacto del proveedor del negocio.'],
                ['Ficha técnica', 'Completá las columnas de los atributos de la categoría de cada producto. Dejá vacíos los que no le correspondan. Las listas admiten varios valores separados por |.'],
                ['Campos obligatorios', 'Se comprueban según la categoría de cada fila. Podés completarlos en la revisión antes de confirmar.'],
                ['Solo catálogo', 'Dejá vacías cantidad_inicial, costo_unitario y proveedor.'],
                $columnas,
                array_map(fn (string $columna): string => $ejemplo[$columna] ?? '', $columnas),
            ] as $valores) {
                $writer->addRow(self::filaDeTexto($valores));
            }
            foreach ($atributos as $campo) {
                $writer->addRow(self::filaDeTexto([$campo['label'], $campo['opciones'] === [] ? 'Respetá la unidad indicada en el encabezado.' : 'Opciones: '.implode(' | ', $campo['opciones'])]));
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
