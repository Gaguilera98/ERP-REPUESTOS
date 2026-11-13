<?php

namespace App\Filament\Negocio\Resources\Traslados\Pages;

use App\Compras\Montos;
use App\Filament\Negocio\Resources\Traslados\TrasladoResource;
use App\Inventario\TrasladarProductos;
use App\Models\Almacen;
use App\Models\CategoriaAtributo;
use App\Models\InventarioStock;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use RuntimeException;

class CreateTraslado extends CreateRecord
{
    protected static string $resource = TrasladoResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        $almacenes = fn (): array => Almacen::query()->orderBy('nombre')->pluck('nombre', 'id')->all();

        return $schema->components([
            Select::make('almacen_origen_id')->label('Almacén de origen')->options($almacenes)
                ->required()->searchable()->native(false)->live()
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    $this->data['cantidades'] = array_fill_keys($this->data['productos'] ?? [], '');

                    if ((int) $get('almacen_origen_id') === (int) $get('almacen_destino_id')) {
                        $set('almacen_destino_id', null);
                    }

                    $this->resetValidation('data.almacen_destino_id');
                }),
            Select::make('almacen_destino_id')->label('Almacén de destino')
                ->options(fn (Get $get): array => Almacen::query()
                    ->whereKeyNot((int) $get('almacen_origen_id'))
                    ->orderBy('nombre')->pluck('nombre', 'id')->all())
                ->validationMessages([
                    'different' => 'Elegí un almacén distinto del origen.',
                    'in' => 'Elegí un almacén distinto del origen.',
                ])
                ->required()->different('almacen_origen_id')->searchable()->native(false)->live(),
            Group::make([
                View::make('filament.negocio.resources.traslados.cargando-productos'),
                $this->selectorProductos(),
            ])->columnSpanFull()->gap(false),
            View::make('filament.negocio.resources.traslados.planilla')
                ->visible(fn (): bool => $this->tieneRuta())->columnSpanFull(),
            Textarea::make('observacion')->label('Observación')->maxLength(500)
                ->placeholder('Ej. Reposición del mostrador de San Pedro')->columnSpanFull(),
        ]);
    }

    private function selectorProductos(): Select
    {
        return Select::make('productos')->label('Agregar productos')->multiple()->searchable()->live()
            ->extraFieldWrapperAttributes([
                'wire:loading.remove' => true,
                'wire:target' => 'data.almacen_destino_id',
            ])
            ->allowHtml()
            ->visible(fn (): bool => $this->tieneRuta())
            ->afterStateUpdated(function (?array $state): void {
                $vacias = array_fill_keys($state ?? [], '');
                $this->data['cantidades'] = array_replace($vacias, array_intersect_key($this->data['cantidades'] ?? [], $vacias));
            })
            ->searchPrompt('Buscá por nombre, SKU, marca o código de barras')
            ->getSearchResultsUsing(function (string $search): array {
                $patron = '%'.mb_substr(trim($search), 0, 100).'%';

                return $this->opcionesProducto(ProductoVariante::query()
                    ->where(fn (Builder $query): Builder => $query->whereLike('sku', $patron)
                        ->orWhereLike('codigo_barras', $patron)
                        ->orWhereHas('productoMaestro', fn (Builder $producto): Builder => $producto->whereLike('nombre', $patron)
                            ->orWhereHas('marca', fn (Builder $marca): Builder => $marca->whereLike('nombre', $patron))))
                    ->orderBy('sku')->limit(30), mostrarFicha: true);
            })
            ->getOptionLabelsUsing(fn (array $values): array => $this->opcionesProducto(ProductoVariante::query()->whereKey($values)))
            ->columnSpanFull();
    }

    #[Computed]
    public function filas(): array
    {
        $origen = (int) ($this->data['almacen_origen_id'] ?? 0);
        $destino = (int) ($this->data['almacen_destino_id'] ?? 0);
        $ids = $this->data['productos'] ?? [];

        return ProductoVariante::query()->whereKey($ids)
            ->with(['productoMaestro.unidadBase', 'productoMaestro.marca',
                'stocks' => fn (HasMany $stocks): HasMany => $stocks->whereIn('almacen_id', [$origen, $destino])])
            ->orderBy('sku')->get()->map(function (ProductoVariante $variante) use ($origen, $destino): array {
                $stockOrigen = $variante->stocks->firstWhere('almacen_id', $origen);
                $stockDestino = $variante->stocks->firstWhere('almacen_id', $destino);
                $fisicoOrigen = $stockOrigen?->cantidad_actual ?? '0';

                return [
                    'id' => $variante->id,
                    'sku' => $variante->sku,
                    'nombre' => $variante->productoMaestro?->nombre,
                    'marca' => $variante->productoMaestro?->marca?->nombre,
                    'unidad' => $variante->productoMaestro?->unidadBase?->simbolo,
                    'disponible' => $this->disponible($stockOrigen),
                    'origen' => Montos::sinCeros($fisicoOrigen),
                    'destino' => Montos::sinCeros($stockDestino?->cantidad_actual ?? '0'),
                ];
            })->all();
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Confirmar traslado');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $usuario = auth('tenant')->user();

        if (! $usuario instanceof TenantUser) {
            throw new RuntimeException('El traslado lo carga un usuario del negocio.');
        }

        $cantidades = array_intersect_key($this->data['cantidades'] ?? [], array_flip($data['productos'] ?? []));
        $reglas = ['data.cantidades' => ['required', 'array']];

        foreach ($data['productos'] ?? [] as $id) {
            $reglas['data.cantidades.'.$id] = ['required', 'regex:/^\d{1,10}([.,]\d{1,4})?$/D'];
        }

        Validator::make(['data' => ['cantidades' => $cantidades]], $reglas, [
            'data.cantidades.required' => 'Agregá al menos un producto e ingresá su cantidad.',
            'data.cantidades.*.required' => 'Ingresá la cantidad a mover.',
            'data.cantidades.*.regex' => 'Ingresá una cantidad válida, con hasta 4 decimales.',
        ])->validate();

        $lineas = [];
        foreach ($cantidades as $id => $cantidad) {
            $lineas[] = ['producto_variante_id' => (int) $id, 'cantidad' => str_replace(',', '.', (string) $cantidad)];
        }

        try {
            return app(TrasladarProductos::class)->trasladar(
                (int) $data['almacen_origen_id'],
                (int) $data['almacen_destino_id'],
                $lineas,
                $usuario,
                $data['observacion'] ?? null,
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['data.cantidades' => $exception->validator->errors()->all()]);
        }
    }

    private function tieneRuta(): bool
    {
        $origen = (int) ($this->data['almacen_origen_id'] ?? 0);
        $destino = (int) ($this->data['almacen_destino_id'] ?? 0);

        return $origen > 0 && $destino > 0 && $origen !== $destino;
    }

    /** @return array<int, string> */
    private function opcionesProducto(Builder $query, bool $mostrarFicha = false): array
    {
        $query->with('productoMaestro');

        if ($mostrarFicha) {
            $origen = (int) ($this->data['almacen_origen_id'] ?? 0);
            $query->with([
                'productoMaestro.marca',
                'productoMaestro.unidadBase',
                'productoMaestro.categoria.atributos.unidadMedida',
                'stocks' => fn (HasMany $stocks): HasMany => $stocks->where('almacen_id', $origen),
            ]);
        }

        return $query->get()->mapWithKeys(function (ProductoVariante $variante) use ($mostrarFicha): array {
            $producto = $variante->productoMaestro;
            if (! $mostrarFicha) {
                return [$variante->id => e($producto?->nombre ?? $variante->sku)];
            }

            $stock = $variante->stocks->first();
            $valores = $variante->atributos_json;
            $ficha = $producto?->categoria?->atributos
                ->filter(fn (CategoriaAtributo $atributo): bool => filled($valores[$atributo->clave] ?? null))
                ->map(function (CategoriaAtributo $atributo) use ($valores): string {
                    $valor = $valores[$atributo->clave];
                    $texto = is_array($valor) ? implode(', ', $valor) : (string) $valor;

                    return $atributo->etiqueta.': '.trim($texto.' '.$atributo->unidadMedida?->simbolo);
                })->implode(' · ');

            return [$variante->id => view('filament.negocio.resources.traslados.opcion-producto', [
                'nombre' => $producto?->nombre ?? $variante->sku,
                'identificacion' => implode(' · ', array_filter([$variante->sku, $producto?->marca?->nombre])),
                'categoria' => $producto?->categoria?->nombre,
                'ficha' => $ficha,
                'ubicacion' => $stock?->ubicacion,
                'disponible' => $this->disponible($stock),
                'unidad' => $producto?->unidadBase?->simbolo,
            ])->render()];
        })->all();
    }

    private function disponible(?InventarioStock $stock): string
    {
        return Montos::sinCeros(bcsub($stock?->cantidad_actual ?? '0', $stock?->cantidad_reservada ?? '0', 4));
    }
}
