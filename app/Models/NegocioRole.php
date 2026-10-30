<?php

namespace App\Models;

use App\Exceptions\Tenancy\TenantContextMismatchException;
use App\Tenancy\PlatformTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role as SpatieRole;

class NegocioRole extends SpatieRole
{
    public function getConnectionName(): ?string
    {
        if (tenancy()->initialized && tenant() !== null) {
            $pool = tenant()->db_pool;

            return is_string($pool) && $pool !== '' ? $pool : parent::getConnectionName();
        }

        $connection = config('tenancy.database.central_connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $builder->where(
                $builder->getModel()->qualifyColumn('tenant_id'),
                static::teamKey(),
            );
        });

        static::creating(function (Model $model): void {
            $teamKey = static::teamKey();
            $current = $model->getAttribute('tenant_id');

            if ($current === null || $current === '') {
                $model->setAttribute('tenant_id', $teamKey);

                return;
            }

            if ((string) $current !== $teamKey) {
                throw new TenantContextMismatchException;
            }
        });
    }

    protected static function teamKey(): string
    {
        if (tenancy()->initialized && tenant() !== null) {
            return (string) tenant()->getTenantKey();
        }

        return PlatformTeam::Id;
    }
}
