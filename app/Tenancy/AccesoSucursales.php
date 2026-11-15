<?php

namespace App\Tenancy;

use App\Models\Almacen;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class AccesoSucursales
{
    public const ADMINISTRADOR = 'administrador';

    public static function etiquetaRol(string $rol): string
    {
        return match ($rol) {
            self::ADMINISTRADOR => 'Administrador del negocio',
            'cajero' => 'Vendedor',
            default => str($rol)->headline()->toString(),
        };
    }

    /**
     * @return list<int>
     */
    public function ids(TenantUser $usuario): array
    {
        return $this->consulta($usuario)->pluck('sucursales.id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    public function autoriza(TenantUser $usuario, int $sucursalId): bool
    {
        return $this->consulta($usuario)->whereKey($sucursalId)->exists();
    }

    public function consulta(TenantUser $usuario, bool $operativas = false): Builder
    {
        return Sucursal::query()
            ->where('tenant_id', $usuario->tenant_id)
            ->when($operativas, fn (Builder $query) => $query->where('is_active', true))
            ->unless($usuario->hasRole(self::ADMINISTRADOR), fn (Builder $query) => $query
                ->whereIn('id', $usuario->sucursales()->select('sucursales.id')->getQuery()));
    }

    public function disponibles(TenantUser $usuario): Builder
    {
        return $this->consulta($usuario, operativas: true);
    }

    public function almacenes(TenantUser $usuario): Builder
    {
        return Almacen::query()->where('tenant_id', $usuario->tenant_id)->unless($usuario->hasRole(self::ADMINISTRADOR), fn (Builder $query) => $query->whereIn(
            'id', SucursalAlmacen::query()->whereIn('sucursal_id', $this->consulta($usuario)->select('sucursales.id'))->select('almacen_id'),
        ));
    }

    public function activa(TenantUser $usuario): ?int
    {
        $sucursales = $this->disponibles($usuario)->get(['id']);
        $elegida = session()->get($this->clave($usuario));

        if ($elegida !== null && $sucursales->contains('id', $elegida)) {
            return (int) $elegida;
        }

        session()->forget($this->clave($usuario));

        return $sucursales->count() === 1 ? (int) $sucursales->first()->id : null;
    }

    public function seleccionar(TenantUser $usuario, int $id): void
    {
        abort_unless($this->disponibles($usuario)->whereKey($id)->exists(), 403);
        session()->put($this->clave($usuario), $id);
    }

    public function filtrarSucursal(Builder $query, TenantUser $usuario, string $columna = 'sucursal_id'): Builder
    {
        return $query->whereIn($columna, $this->consulta($usuario)->select('sucursales.id'));
    }

    public function filtrarAlmacen(Builder $query, TenantUser $usuario, string $columna = 'almacen_id'): Builder
    {
        return $query->whereIn($columna, $this->almacenes($usuario)->select('almacenes.id'));
    }

    public function autorizaAlmacen(TenantUser $usuario, int $id): bool
    {
        return $this->almacenes($usuario)->whereKey($id)->exists();
    }

    public function validarAlmacen(TenantUser $usuario, int $id, string $campo = 'almacen_id'): void
    {
        if (! $this->autorizaAlmacen($usuario, $id)) {
            throw ValidationException::withMessages([$campo => 'Ese almacén está fuera de tus sucursales asignadas.']);
        }
    }

    public function puedeVerStock(TenantUser $usuario): bool
    {
        return $usuario->can('ViewAny:InventarioStock');
    }

    public function validarOperacion(TenantUser $usuario, int $id): void
    {
        if ($this->activa($usuario) !== $id) {
            throw ValidationException::withMessages(['sucursal_id' => 'Revisá la sucursal activa antes de continuar.']);
        }
    }

    private function clave(TenantUser $usuario): string
    {
        return "sucursal_activa.{$usuario->tenant_id}.{$usuario->getKey()}";
    }
}
