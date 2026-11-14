<?php

namespace App\Filament\Negocio\Resources\ProductoMaestros\Concerns;

use App\Catalogo\PreciosDeReferencia;
use App\Catalogo\VariantesProducto;
use App\Models\ProductoMaestro;

trait GuardaVariantesDelProducto
{
    /** @var array<string, mixed> */
    protected array $estadoVariantes = [];

    public function hasDatabaseTransactions(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->estadoVariantes = app(VariantesProducto::class)->prepararPrecios($data, app(PreciosDeReferencia::class));

        return $this->datosDelProducto($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->estadoVariantes = app(VariantesProducto::class)->prepararPrecios($data, app(PreciosDeReferencia::class));

        return $this->datosDelProducto($data);
    }

    protected function afterCreate(): void
    {
        $this->guardarVariantes();
    }

    protected function afterSave(): void
    {
        $this->guardarVariantes();
    }

    protected function guardarVariantes(): void
    {
        $producto = $this->getRecord();

        if (! $producto instanceof ProductoMaestro) {
            return;
        }

        app(VariantesProducto::class)->guardar($producto, $this->estadoVariantes);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function datosDelProducto(array $data): array
    {
        unset(
            $data['con_variantes'],
            $data['variante_id'],
            $data['sku'],
            $data['codigo_barras'],
            $data['precio_venta'],
            $data['precio_mayor'],
            $data['precio_venta_dolar'],
            $data['precio_mayor_dolar'],
            $data['ficha'],
            $data['opciones'],
            $data['variantes'],
            $data['ubicaciones'],
            $data['empaques'],
        );

        return $data;
    }
}
