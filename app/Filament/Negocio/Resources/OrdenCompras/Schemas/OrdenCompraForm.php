<?php

namespace App\Filament\Negocio\Resources\OrdenCompras\Schemas;

use App\Compras\Montos;
use App\Enums\EstadoOrdenCompra;
use App\Enums\UsoUnidad;
use App\Models\Almacen;
use App\Models\NegocioConfiguracion;
use App\Models\OrdenCompra;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\ProveedorContacto;
use App\Models\Trabajador;
use App\Models\UnidadMedida;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

class OrdenCompraForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->disabled(fn (?OrdenCompra $record): bool => $record instanceof OrdenCompra && $record->estado !== EstadoOrdenCompra::Pendiente)
            ->components([
                Placeholder::make('aviso_recepcion')
                    ->hiddenLabel()
                    ->content('Guardar la compra no mete mercadería. Recibir muestra el costo por unidad y pide confirmación antes de tocar el stock.')
                    ->visible(fn (?OrdenCompra $record): bool => ! $record instanceof OrdenCompra || $record->estado === EstadoOrdenCompra::Pendiente),
                Section::make('Compra')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->columns(2)
                    ->schema([
                        Select::make('proveedor_id')
                            ->label('Proveedor')
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
                            ->options(fn (): array => Almacen::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
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
                            ->maxLength(255),
                        Select::make('persona_recibe_trabajador_id')
                            ->label('Quién recibe')
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
                    ->description('El costo queda en la compra. El precio de venta no se mueve.')
                    ->schema([
                        Repeater::make('lineas')
                            ->hiddenLabel()
                            ->compact()
                            ->live()
                            ->defaultItems(1)
                            ->minItems(1)
                            ->addActionLabel('Agregar producto')
                            ->addActionAlignment(Alignment::Start)
                            ->reorderable(false)
                            ->disabled(fn (?OrdenCompra $record): bool => $record instanceof OrdenCompra && $record->estado !== EstadoOrdenCompra::Pendiente)
                            ->dehydrated(fn (?OrdenCompra $record): bool => ! $record instanceof OrdenCompra || $record->estado === EstadoOrdenCompra::Pendiente)
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                self::sincronizarImpuesto($set, $get);
                            })
                            ->table([
                                TableColumn::make('Producto')->markAsRequired(),
                                TableColumn::make('Unidad')->markAsRequired()->width('12rem'),
                                TableColumn::make('Cantidad')->markAsRequired()->width('9rem'),
                                TableColumn::make('Costo')->markAsRequired()->width('10rem'),
                                TableColumn::make('Subtotal')->alignment(Alignment::End)->width('9rem'),
                            ])
                            ->schema([
                                Select::make('producto_variante_id')
                                    ->hiddenLabel()
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
                                    ->hiddenLabel()
                                    ->options(fn (Get $get): array => self::unidadesDeLinea($get('producto_variante_id')))
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->live(),
                                TextInput::make('cantidad_pedida')
                                    ->hiddenLabel()
                                    ->suffix(fn (Get $get): ?string => self::simboloDeLinea($get('unidad_medida_id')))
                                    ->numeric()
                                    ->minValue(0.0001)
                                    ->required()
                                    ->live(onBlur: true),
                                TextInput::make('costo_unitario')
                                    ->hiddenLabel()
                                    ->prefix(NegocioConfiguracion::codigoMoneda())
                                    ->numeric()
                                    ->minValue(0)
                                    ->required()
                                    ->live(onBlur: true),
                                Placeholder::make('subtotal_linea')
                                    ->hiddenLabel()
                                    ->weight(FontWeight::SemiBold)
                                    ->content(fn (Get $get): string => self::dinero(self::subtotalLinea($get('cantidad_pedida'), $get('costo_unitario')))),
                            ]),
                        Grid::make(12)
                            ->schema([
                                Group::make([
                                    ToggleButtons::make('forma_impuesto')
                                        ->label('Impuesto')
                                        ->options([
                                            'porcentaje' => 'Porcentaje',
                                            'importe' => 'Importe',
                                        ])
                                        ->default('importe')
                                        ->inline()
                                        ->grouped()
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                            if ($state === 'importe' && is_numeric($get('porcentaje_impuesto'))) {
                                                $set('impuestos', self::montoDesdePorcentaje($get('porcentaje_impuesto'), $get('lineas')));

                                                return;
                                            }

                                            self::sincronizarImpuesto($set, $get);
                                        }),
                                    TextInput::make('porcentaje_impuesto')
                                        ->label('Porcentaje')
                                        ->suffix('%')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(100)
                                        ->live(onBlur: true)
                                        ->visible(fn (Get $get): bool => $get('forma_impuesto') === 'porcentaje')
                                        ->helperText('Si cambiás los productos, se vuelve a calcular. No entra al costo.'),
                                    TextInput::make('impuestos')
                                        ->label('Importe')
                                        ->prefix(NegocioConfiguracion::codigoMoneda())
                                        ->numeric()
                                        ->minValue(0)
                                        ->default(0)
                                        ->live(onBlur: true)
                                        ->visible(fn (Get $get): bool => $get('forma_impuesto') !== 'porcentaje')
                                        ->helperText('De la factura. No entra al costo.'),
                                ])
                                    ->columns(1)
                                    ->columnSpan(['default' => 'full', 'lg' => 5]),
                                Group::make([
                                    Placeholder::make('subtotal_orden')
                                        ->label('Subtotal')
                                        ->inlineLabel()
                                        ->content(fn (Get $get): string => self::dinero(self::subtotalOrden($get('lineas')))),
                                    Placeholder::make('impuesto_orden')
                                        ->label('Impuesto')
                                        ->inlineLabel()
                                        ->content(fn (Get $get): string => self::dinero(self::impuestoMostrado($get))),
                                    Placeholder::make('total_orden')
                                        ->label('Total')
                                        ->inlineLabel()
                                        ->weight(FontWeight::Bold)
                                        ->content(fn (Get $get): string => self::dinero(Montos::sumar(
                                            self::subtotalOrden($get('lineas')),
                                            self::impuestoMostrado($get),
                                            2,
                                        ))),
                                ])
                                    ->columns(1)
                                    ->columnStart(['lg' => 8])
                                    ->columnSpan(['default' => 'full', 'lg' => 5]),
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

    private static function subtotalLinea(mixed $cantidad, mixed $costo): string
    {
        if (! is_numeric($cantidad) || ! is_numeric($costo)) {
            return '0.00';
        }

        return Montos::multiplicar($cantidad, $costo, 2);
    }

    private static function subtotalOrden(mixed $lineas): string
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
                self::subtotalLinea($linea['cantidad_pedida'] ?? null, $linea['costo_unitario'] ?? null),
                2,
            );
        }

        return $subtotal;
    }

    private static function sincronizarImpuesto(Set $set, Get $get): void
    {
        if ($get('forma_impuesto') !== 'porcentaje' || ! is_numeric($get('porcentaje_impuesto'))) {
            return;
        }

        $importe = self::montoDesdePorcentaje($get('porcentaje_impuesto'), $get('lineas'));

        if ((string) $get('impuestos') === $importe) {
            return;
        }

        $set('impuestos', $importe);
    }

    private static function impuestoMostrado(Get $get): string
    {
        if ($get('forma_impuesto') === 'porcentaje' && is_numeric($get('porcentaje_impuesto'))) {
            return self::montoDesdePorcentaje($get('porcentaje_impuesto'), $get('lineas'));
        }

        return self::impuestoEnPantalla($get('impuestos'));
    }

    private static function montoDesdePorcentaje(mixed $porcentaje, mixed $lineas): string
    {
        if (! is_numeric($porcentaje) || (float) $porcentaje < 0 || (float) $porcentaje > 100) {
            return '0.00';
        }

        return Montos::porcentaje(self::subtotalOrden($lineas), $porcentaje, 2);
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
            ])
            ->all();
        $data['forma_impuesto'] = $orden->porcentaje_impuesto === null ? 'importe' : 'porcentaje';
        $data['porcentaje_impuesto'] = $orden->porcentaje_impuesto;

        return $data;
    }

    private static function impuestoEnPantalla(mixed $valor): string
    {
        if (! is_numeric($valor) || (float) $valor < 0) {
            return '0.00';
        }

        return Montos::dos($valor);
    }

    private static function dinero(string $monto): string
    {
        return NegocioConfiguracion::codigoMoneda().' '.$monto;
    }

    private static function simboloDeLinea(mixed $unidadId): ?string
    {
        if ($unidadId === null || $unidadId === '') {
            return null;
        }

        return UnidadMedida::query()->whereKey($unidadId)->value('simbolo');
    }
}
