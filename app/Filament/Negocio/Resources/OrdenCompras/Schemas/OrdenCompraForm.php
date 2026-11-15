<?php

namespace App\Filament\Negocio\Resources\OrdenCompras\Schemas;

use App\Catalogo\PreciosDeReferencia;
use App\Compras\Montos;
use App\Enums\EstadoOrdenCompra;
use App\Enums\MonedaLineaCompra;
use App\Enums\UsoUnidad;
use App\Models\NegocioConfiguracion;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraDetalle;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\ProveedorContacto;
use App\Models\Trabajador;
use App\Models\UnidadMedida;
use App\Tenancy\AccesoSucursales;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class OrdenCompraForm
{
    public static function configure(Schema $schema): Schema
    {
        $conDolar = app(PreciosDeReferencia::class)->habilitado();
        $monedaLocal = NegocioConfiguracion::codigoMoneda();
        $cambioVigente = $conDolar ? app(PreciosDeReferencia::class)->vigente($monedaLocal)?->valor : null;
        $cambioPorProducto = function (?OrdenCompra $record): Collection {
            return $record?->loadMissing('detalles')->detalles
                ->filter(fn (OrdenCompraDetalle $detalle): bool => $detalle->moneda === MonedaLineaCompra::Usd)
                ->pluck('tipo_cambio_valor', 'producto_variante_id') ?? collect();
        };
        $cambio = fn (Get $get, ?OrdenCompra $record): ?string => $cambioPorProducto($record)->get($get('producto_variante_id'), $cambioVigente);

        return $schema
            ->columns(1)
            ->disabled(fn (?OrdenCompra $record): bool => $record instanceof OrdenCompra && $record->estado !== EstadoOrdenCompra::Pendiente)
            ->components([
                Callout::make('Recepción parcial')
                    ->description('Esta compra quedó recibida a medias. Todavía falta mercadería. Usá Recibir para lo que falta.')
                    ->warning()
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->visible(fn (?OrdenCompra $record): bool => $record instanceof OrdenCompra
                        && $record->estado === EstadoOrdenCompra::RecibidaParcial),
                Callout::make('Compra completa')
                    ->description('Esta compra ya recibió toda la mercadería.')
                    ->success()
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->visible(fn (?OrdenCompra $record): bool => $record instanceof OrdenCompra
                        && $record->estado === EstadoOrdenCompra::Completada),
                Callout::make('Compra anulada')
                    ->description('Esta compra está anulada. No se recibe mercadería.')
                    ->danger()
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->visible(fn (?OrdenCompra $record): bool => $record instanceof OrdenCompra
                        && $record->estado === EstadoOrdenCompra::Anulada),
                Section::make('Información general')
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Select::make('proveedor_id')
                            ->label('Proveedor')
                            ->placeholder('Elegí un proveedor')
                            ->prefixIcon(Heroicon::OutlinedBuildingStorefront)
                            ->options(fn (): array => Proveedor::query()
                                ->orderByDesc('es_ocasional')
                                ->orderBy('nombre_empresa')
                                ->get()
                                ->mapWithKeys(fn (Proveedor $proveedor): array => [
                                    $proveedor->id => $proveedor->es_ocasional
                                        ? $proveedor->nombre_empresa.' (ocasional)'
                                        : $proveedor->nombre_empresa,
                                ])
                                ->all())
                            ->required()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live()
                            ->createOptionForm([
                                TextInput::make('nombre_empresa')
                                    ->label('Empresa')
                                    ->required()
                                    ->maxLength(255),
                            ])
                            ->createOptionUsing(fn (array $data): int => Proveedor::query()->create($data)->getKey())
                            ->createOptionAction(fn (Action $action): Action => $action
                                ->visible(fn (): bool => (bool) auth('tenant')->user()?->can('create', Proveedor::class))
                                ->modalHeading('Nuevo proveedor')),
                        Select::make('proveedor_contacto_id')
                            ->label('Contacto')
                            ->prefixIcon(Heroicon::OutlinedUser)
                            ->placeholder('Sin contacto')
                            ->options(fn (Get $get): array => ProveedorContacto::query()
                                ->where('proveedor_id', $get('proveedor_id'))
                                ->orderBy('nombres')
                                ->get()
                                ->mapWithKeys(fn (ProveedorContacto $contacto): array => [
                                    $contacto->id => $contacto->nombreCompleto(),
                                ])
                                ->all())
                            ->searchable()
                            ->native(false),
                        Select::make('almacen_destino_id')
                            ->label('Almacén que recibe')
                            ->placeholder('Elegí un almacén')
                            ->prefixIcon(Heroicon::OutlinedBuildingOffice2)
                            ->options(fn (): array => app(AccesoSucursales::class)->almacenes(auth('tenant')->user())->orderBy('nombre')->pluck('nombre', 'id')->all())
                            ->required()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->disabled(fn (?OrdenCompra $record): bool => $record instanceof OrdenCompra && $record->estado !== EstadoOrdenCompra::Pendiente)
                            ->dehydrated(fn (?OrdenCompra $record): bool => ! $record instanceof OrdenCompra || $record->estado === EstadoOrdenCompra::Pendiente),
                        DateTimePicker::make('fecha_emision')
                            ->label('Fecha de emisión')
                            ->default(now())
                            ->seconds(false)
                            ->required()
                            ->native(false),
                        TextInput::make('persona_entrega_nombre')
                            ->label('Quién entrega')
                            ->prefixIcon(Heroicon::OutlinedUser)
                            ->placeholder('Nombre de quien entrega')
                            ->maxLength(255),
                        Select::make('persona_recibe_trabajador_id')
                            ->label('Quién recibe')
                            ->prefixIcon(Heroicon::OutlinedUser)
                            ->placeholder('Sin asignar')
                            ->options(fn (): array => Trabajador::query()
                                ->where('is_active', true)
                                ->orderBy('nombres')
                                ->get()
                                ->mapWithKeys(fn (Trabajador $trabajador): array => [
                                    $trabajador->id => $trabajador->nombreCompleto(),
                                ])
                                ->all())
                            ->searchable()
                            ->native(false),
                    ]),
                Section::make('Productos')
                    ->icon(Heroicon::OutlinedCube)
                    ->afterHeader(fn (Section $component, string $operation): array => $operation === 'view' ? [] : [
                        View::make('filament.negocio.resources.orden-compras.agregar-producto')
                            ->viewData(function () use ($component): array {
                                $lineas = $component->getChildSchema()->getComponent(fn ($field): bool => $field instanceof Repeater);

                                return ['agregar' => $lineas?->getAction($lineas->getAddActionName())];
                            }),
                    ])
                    ->description(fn (string $operation): ?string => $operation === 'view'
                        ? null
                        : 'El stock se actualiza al recibir la mercadería.')
                    ->schema([
                        View::make('filament.negocio.resources.orden-compras.productos')
                            ->visibleOn('view')
                            ->viewData(function (OrdenCompra $record): array {
                                $record->loadMissing(['detalles.variante.productoMaestro.unidadBase', 'detalles.unidad']);

                                return ['compra' => $record, 'moneda' => NegocioConfiguracion::codigoMoneda()];
                            }),
                        Repeater::make('lineas')
                            ->hiddenOn('view')
                            ->hiddenLabel()
                            ->compact()
                            ->live()
                            ->defaultItems(1)
                            ->minItems(1)
                            ->addActionLabel('Agregar producto')
                            ->addAction(fn (Action $action): Action => $action->icon(Heroicon::OutlinedPlusCircle)->color('primary')->outlined())
                            ->extraAttributes(['class' => 'compra-productos'])
                            ->belowContent(fn (Repeater $component): View => View::make('filament.negocio.resources.orden-compras.pie-productos')
                                ->viewData(fn (?OrdenCompra $record): array => [
                                    'total' => $monedaLocal.' '.self::subtotalOrden($component->getRawState(), $cambioPorProducto($record), $cambioVigente),
                                ]))
                            ->reorderable(false)
                            ->disabled(fn (?OrdenCompra $record): bool => $record instanceof OrdenCompra && $record->estado !== EstadoOrdenCompra::Pendiente)
                            ->dehydrated(fn (?OrdenCompra $record): bool => ! $record instanceof OrdenCompra || $record->estado === EstadoOrdenCompra::Pendiente)
                            ->columns(['default' => 1, 'sm' => 2, 'xl' => 16])
                            ->schema([
                                Select::make('producto_variante_id')
                                    ->label('Producto')
                                    ->placeholder('Buscá por nombre o SKU')
                                    ->columnSpan(['default' => 'full', 'xl' => 5])
                                    ->options(fn (): array => ProductoVariante::query()
                                        ->with('productoMaestro')
                                        ->orderBy('sku')
                                        ->get()
                                        ->mapWithKeys(fn (ProductoVariante $variante): array => [
                                            $variante->id => $variante->sku.' · '.$variante->productoMaestro?->nombre,
                                        ])
                                        ->all())
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                                        $variante = ProductoVariante::query()->with('productoMaestro')->find($state);
                                        $set('unidad_medida_id', $variante?->productoMaestro?->unidad_base_id);
                                    }),
                                Select::make('unidad_medida_id')
                                    ->label('Unidad de compra')
                                    ->columnSpan(['xl' => 3])
                                    ->placeholder('Elegí la unidad')
                                    ->options(fn (Get $get): array => self::unidadesDeLinea($get('producto_variante_id')))
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->live(),
                                TextInput::make('cantidad_pedida')
                                    ->label('Cantidad')
                                    ->columnSpan(['xl' => 2])
                                    ->suffix(fn (Get $get): ?string => self::simboloDeLinea($get('unidad_medida_id')))
                                    ->numeric()
                                    ->minValue(0.0001)
                                    ->required()
                                    ->live(onBlur: true),
                                Group::make([
                                    Select::make('moneda')
                                        ->label('Moneda')
                                        ->options(['local' => $monedaLocal, 'USD' => 'USD'])
                                        ->default('local')
                                        ->selectablePlaceholder(false)
                                        ->native(false)
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function (Set $set): void {
                                            $set('costo_unitario', null);
                                        })
                                        ->visible(fn (Get $get): bool => $conDolar || $get('moneda') === MonedaLineaCompra::Usd->value),
                                    TextInput::make('costo_unitario')
                                        ->label('Costo por unidad')
                                        ->prefix(fn (Get $get): string => $get('moneda') === MonedaLineaCompra::Usd->value ? 'USD' : $monedaLocal)
                                        ->numeric()
                                        ->minValue(0)
                                        ->required()
                                        ->live(onBlur: true),
                                    TextEntry::make('equivalencia_costo')
                                        ->hiddenLabel()
                                        ->columnSpanFull()
                                        ->size('xs')
                                        ->state(function (Get $get, ?OrdenCompra $record) use ($cambio, $monedaLocal): string {
                                            $cotizacion = $cambio($get, $record);
                                            if ($cotizacion === null) {
                                                return 'Publicá un tipo de cambio antes de registrar un costo en USD.';
                                            }

                                            $costo = self::costoLocal($get('costo_unitario'), $get('moneda'), $cotizacion);
                                            $tipoCambio = '1 USD = '.$monedaLocal.' '.self::costoVisible($cotizacion);

                                            return $costo === null
                                                ? $tipoCambio
                                                : 'Equivale a '.$monedaLocal.' '.self::costoVisible($costo).' por unidad. '.$tipoCambio;
                                        })
                                        ->visible(fn (Get $get): bool => $get('moneda') === MonedaLineaCompra::Usd->value),
                                ])->columnSpan(['default' => 'full', 'xl' => 4])
                                    ->columns(['default' => 1, 'sm' => 2]),
                                TextEntry::make('subtotal_linea')
                                    ->label('Subtotal')
                                    ->columnSpan(['default' => 'full', 'xl' => 2])
                                    ->extraAttributes(['class' => 'tabular-nums'])
                                    ->alignEnd()
                                    ->weight(FontWeight::SemiBold)
                                    ->state(fn (Get $get, ?OrdenCompra $record): string => $monedaLocal.' '.self::subtotalLinea(
                                        $get('cantidad_pedida'),
                                        $get('costo_unitario'),
                                        $get('moneda'),
                                        $cambio($get, $record),
                                    )),
                            ]),
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function unidadesDeLinea(mixed $varianteId): array
    {
        $variante = ProductoVariante::query()
            ->with(['productoMaestro.unidadBase', 'productoMaestro.empaques.unidad'])
            ->find($varianteId);

        $producto = $variante?->productoMaestro;

        if ($producto === null) {
            return [];
        }

        $opciones = [];
        $base = $producto->unidadBase;

        if ($base instanceof UnidadMedida) {
            $opciones[$base->id] = $base->nombre.' ('.$base->simbolo.')';

            if ($base->uso === UsoUnidad::Cantidad) {
                foreach (UnidadMedida::query()->where('uso', UsoUnidad::Cantidad)->where('magnitud', $base->magnitud)->orderBy('nombre')->get() as $unidad) {
                    $opciones[$unidad->id] = $unidad->nombre.' ('.$unidad->simbolo.')';
                }
            }
        }

        foreach ($producto->empaques as $empaque) {
            if ($empaque->unidad instanceof UnidadMedida) {
                $opciones[$empaque->unidad->id] = $empaque->unidad->nombre.' ('.$empaque->unidad->simbolo.') = '.$empaque->factor_a_base.' '.$base?->simbolo;
            }
        }

        return $opciones;
    }

    private static function subtotalLinea(mixed $cantidad, mixed $costo, mixed $moneda, mixed $cambio): string
    {
        $costoLocal = self::costoLocal($costo, $moneda, $cambio);

        if (! self::decimal($cantidad) || $costoLocal === null) {
            return '0.00';
        }

        return Montos::multiplicar(Montos::cuatro($cantidad), $costoLocal, 2);
    }

    private static function costoLocal(mixed $costo, mixed $moneda, mixed $cambio): ?string
    {
        if (! self::decimal($costo)) {
            return null;
        }

        $costo = Montos::cuatro($costo);
        if ($moneda !== MonedaLineaCompra::Usd->value) {
            return $costo;
        }

        return self::decimal($cambio) ? Montos::multiplicar($costo, $cambio, 4) : null;
    }

    private static function decimal(mixed $valor): bool
    {
        return is_scalar($valor) && preg_match('/^\d+(\.\d+)?$/', (string) $valor) === 1;
    }

    private static function subtotalOrden(mixed $lineas, Collection $cambiosGuardados, ?string $cambioVigente): string
    {
        $subtotal = '0.00';

        if (! is_array($lineas)) {
            return $subtotal;
        }

        foreach ($lineas as $linea) {
            if (! is_array($linea)) {
                continue;
            }

            $subtotal = Montos::sumar(
                $subtotal,
                self::subtotalLinea(
                    $linea['cantidad_pedida'] ?? null,
                    $linea['costo_unitario'] ?? null,
                    $linea['moneda'] ?? null,
                    $cambiosGuardados->get($linea['producto_variante_id'] ?? '', $cambioVigente),
                ),
                2,
            );
        }

        return $subtotal;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function conLineasGuardadas(array $data, mixed $orden): array
    {
        if (! $orden instanceof OrdenCompra) {
            return $data;
        }

        $orden->loadMissing('detalles');
        $data['lineas'] = $orden->detalles
            ->map(fn ($detalle): array => [
                'producto_variante_id' => $detalle->producto_variante_id,
                'unidad_medida_id' => $detalle->unidad_medida_id,
                'cantidad_pedida' => $detalle->cantidad_pedida,
                'costo_unitario' => $detalle->costo_anotado ?? $detalle->costo_unitario,
                'moneda' => $detalle->moneda->value,
            ])
            ->all();

        return $data;
    }

    public static function costoVisible(string $costo): string
    {
        $dosDecimales = Montos::dos($costo);

        return Montos::comparar($costo, $dosDecimales) === 0 ? $dosDecimales : Montos::sinCeros($costo);
    }

    private static function simboloDeLinea(mixed $unidadId): ?string
    {
        if ($unidadId === null || $unidadId === '') {
            return null;
        }

        return UnidadMedida::query()->whereKey($unidadId)->value('simbolo');
    }
}
