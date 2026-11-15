<?php

namespace App\Compras;

use App\Enums\EstadoOrdenCompra;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReasignarProveedorFlash
{
    public function ejecutar(OrdenCompra $orden, int $proveedorId, TenantUser $usuario): OrdenCompra
    {
        app(AccesoSucursales::class)->validarAlmacen($usuario, (int) $orden->almacen_destino_id, 'almacen_destino_id');

        return DB::transaction(function () use ($orden, $proveedorId, $usuario): OrdenCompra {
            $orden = OrdenCompra::query()->lockForUpdate()->findOrFail($orden->id);
            abort_unless($usuario->can('update', $orden), 403);

            if (! $orden->es_compra_flash || $orden->estado !== EstadoOrdenCompra::Completada) {
                throw ValidationException::withMessages(['proveedor_id' => 'Solo se reasignan compras express completadas.']);
            }

            $proveedor = Proveedor::query()->find($proveedorId);

            if (! $proveedor instanceof Proveedor || $proveedor->es_ocasional) {
                throw ValidationException::withMessages(['proveedor_id' => 'Elegí un proveedor real de este negocio.']);
            }

            $orden->proveedor_id = $proveedor->id;
            $orden->proveedor_contacto_id = null;
            $orden->save();

            return $orden->refresh();
        });
    }
}
