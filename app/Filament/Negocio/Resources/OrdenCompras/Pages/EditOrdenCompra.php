<?php

namespace App\Filament\Negocio\Resources\OrdenCompras\Pages;

use App\Compras\GuardarOrdenCompra;
use App\Enums\EstadoOrdenCompra;
use App\Filament\Negocio\Resources\OrdenCompras\Actions\AnularOrdenCompraAction;
use App\Filament\Negocio\Resources\OrdenCompras\Actions\RecibirMercaderiaAction;
use App\Filament\Negocio\Resources\OrdenCompras\OrdenCompraResource;
use App\Filament\Negocio\Resources\OrdenCompras\Schemas\OrdenCompraForm;
use App\Models\OrdenCompra;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditOrdenCompra extends EditRecord
{
    protected static string $resource = OrdenCompraResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->compraEstaPendiente()) {
            return;
        }

        $this->redirect(OrdenCompraResource::getUrl('view', ['record' => $this->getRecord()]));
    }

    protected function getHeaderActions(): array
    {
        return [
            RecibirMercaderiaAction::make(),
            AnularOrdenCompraAction::make(),
            DeleteAction::make()
                ->visible(fn (): bool => $this->compraEstaPendiente()),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->visible(fn (): bool => $this->compraEstaPendiente());
    }

    private function compraEstaPendiente(): bool
    {
        $orden = $this->getRecord();

        return $orden instanceof OrdenCompra && $orden->estado === EstadoOrdenCompra::Pendiente;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $orden = $this->getRecord();

        return OrdenCompraForm::conLineasGuardadas($data, $orden);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof OrdenCompra) {
            return $record;
        }

        return app(GuardarOrdenCompra::class)->actualizar($record, $data);
    }
}
