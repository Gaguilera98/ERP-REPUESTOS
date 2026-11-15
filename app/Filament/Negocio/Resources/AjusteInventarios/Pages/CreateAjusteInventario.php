<?php

namespace App\Filament\Negocio\Resources\AjusteInventarios\Pages;

use App\Compras\Montos;
use App\Filament\Negocio\Resources\AjusteInventarios\AjusteInventarioResource;
use App\Inventario\AjustarInventario;
use App\Models\Categoria;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use RuntimeException;

class CreateAjusteInventario extends CreateRecord
{
    protected static string $resource = AjusteInventarioResource::class;

    protected static ?string $title = 'Nuevo conteo';

    protected static bool $canCreateAnother = false;

    public string $busqueda = '';

    public string $categoria = '';

    /** @var list<int|string> */
    public array $seleccion = [];

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('almacen_id')
                ->label('Almacén')
                ->options(fn (): array => app(AccesoSucursales::class)->almacenes(auth('tenant')->user())->orderBy('nombre')->pluck('nombre', 'id')->all())
                ->required()
                ->searchable()
                ->native(false)
                ->helperText('Si cambiás de almacén, se limpian las cantidades ingresadas.')
                ->afterStateUpdated(function (): void {
                    foreach (array_keys($this->data['cantidades'] ?? []) as $id) {
                        $this->data['cantidades'][$id] = '';
                    }
                })
                ->live(),
            View::make('filament.negocio.resources.ajuste-inventarios.planilla')->columnSpanFull(),
        ]);
    }

    #[Computed]
    public function categorias(): array
    {
        return Categoria::query()->orderBy('nombre')->pluck('nombre', 'id')->all();
    }

    #[Computed]
    public function resultados(): array
    {
        $busqueda = mb_substr(trim($this->busqueda), 0, 100);

        if (blank($this->data['almacen_id'] ?? null) || ($busqueda === '' && $this->categoria === '')) {
            return [];
        }
        app(AccesoSucursales::class)->validarAlmacen(auth('tenant')->user(), (int) $this->data['almacen_id']);

        $coincidencia = '%'.$busqueda.'%';

        return ProductoVariante::query()
            ->with([
                'productoMaestro.unidadBase',
                'productoMaestro.marca',
                'productoMaestro.categoria.atributos.unidadMedida',
                'stocks' => fn (HasMany $stocks) => $stocks->where('almacen_id', (int) $this->data['almacen_id']),
            ])
            ->whereNotIn('id', array_keys($this->data['cantidades'] ?? []))
            ->when($this->categoria !== '', fn (Builder $query): Builder => $query
                ->whereHas('productoMaestro', fn (Builder $producto): Builder => $producto->where('categoria_id', $this->categoria)))
            ->when($busqueda !== '', fn (Builder $query): Builder => $query->where(fn (Builder $productos): Builder => $productos
                ->whereLike('sku', $coincidencia)
                ->orWhereLike('codigo_barras', $coincidencia)
                ->orWhereHas('productoMaestro', fn (Builder $producto): Builder => $producto->whereLike('nombre', $coincidencia))))
            ->orderBy('sku')
            ->orderBy('id')
            ->limit(25)
            ->get()
            ->map(function (ProductoVariante $variante): array {
                $producto = $variante->productoMaestro;
                $stock = $variante->stocks->first();
                $atributos = $variante->atributos_json;
                $ficha = $producto?->categoria?->atributos
                    ->filter(fn ($atributo): bool => filled($atributos[$atributo->clave] ?? null))
                    ->map(function ($atributo) use ($atributos): string {
                        $valor = $atributos[$atributo->clave];
                        $texto = is_array($valor) ? implode(', ', $valor) : (string) $valor;

                        return $atributo->etiqueta.': '.trim($texto.' '.$atributo->unidadMedida?->simbolo);
                    })
                    ->implode(' · ');

                return [
                    ...$this->datosProducto($variante),
                    'marca' => $producto?->marca?->nombre,
                    'categoria' => $producto?->categoria?->nombre,
                    'ficha' => $ficha,
                    'stock' => Montos::sinCeros($stock?->cantidad_actual ?? '0'),
                    'ubicacion' => $stock?->ubicacion,
                ];
            })
            ->all();
    }

    #[Computed]
    public function filas(): array
    {
        $ids = array_keys($this->data['cantidades'] ?? []);
        $productos = ProductoVariante::query()
            ->with('productoMaestro.unidadBase')
            ->whereKey($ids)
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->map(fn ($id): ?ProductoVariante => $productos->get($id))
            ->filter()
            ->map(fn (ProductoVariante $variante): array => $this->datosProducto($variante))
            ->all();
    }

    public function updatedBusqueda(): void
    {
        $this->seleccion = [];
    }

    public function updatedCategoria(): void
    {
        $this->seleccion = [];
    }

    public function agregarProductos(bool $todos = false): void
    {
        $this->authorizeAccess();

        $visibles = array_column($this->resultados, 'id');
        $ids = $todos ? $visibles : array_intersect($visibles, array_filter($this->seleccion, 'is_scalar'));

        foreach ($ids as $id) {
            $this->data['cantidades'][$id] ??= '';
        }

        $this->seleccion = [];
        unset($this->filas, $this->resultados);
    }

    public function quitarProducto(int $id): void
    {
        $this->authorizeAccess();

        unset($this->data['cantidades'][$id], $this->filas, $this->resultados);
        $this->resetErrorBag('data.cantidades.'.$id);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Guardar y revisar conteo');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $usuario = auth('tenant')->user();

        if (! $usuario instanceof TenantUser) {
            throw new RuntimeException('El conteo lo carga un usuario del negocio.');
        }

        $cantidades = array_filter($this->data['cantidades'] ?? [], fn (mixed $valor): bool => $valor !== null && $valor !== '');

        Validator::make(['data' => ['cantidades' => $cantidades]], [
            'data.cantidades' => ['required', 'array'],
            'data.cantidades.*' => ['regex:/^\d{1,10}([.,]\d{1,4})?$/D'],
        ], [
            'data.cantidades.required' => 'Ingresá la cantidad de al menos un producto.',
            'data.cantidades.*.regex' => 'Ingresá una cantidad válida, con hasta 4 decimales.',
        ])->validate();

        $lineas = [];

        foreach ($cantidades as $id => $cantidad) {
            if (! ctype_digit((string) $id) || (int) $id <= 0) {
                throw ValidationException::withMessages(['data.cantidades' => 'Elegí productos del negocio.']);
            }

            $lineas[] = [
                'producto_variante_id' => (int) $id,
                'cantidad_fisica' => str_replace(',', '.', (string) $cantidad),
            ];
        }

        try {
            return app(AjustarInventario::class)->abrirConteo((int) $data['almacen_id'], $lineas, $usuario);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'data.cantidades' => $exception->validator->errors()->all(),
            ]);
        }
    }

    /** @return array{id: int, sku: string, nombre: string, unidad: string} */
    private function datosProducto(ProductoVariante $variante): array
    {
        return [
            'id' => $variante->id,
            'sku' => $variante->sku,
            'nombre' => $variante->productoMaestro?->nombre ?? $variante->sku,
            'unidad' => $variante->productoMaestro?->unidadBase?->simbolo ?? '',
        ];
    }
}
