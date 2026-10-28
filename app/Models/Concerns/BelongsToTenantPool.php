<?php

namespace App\Models\Concerns;

use App\Exceptions\Tenancy\TenantContextMismatchException;
use App\Exceptions\Tenancy\TenantContextMissingException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToTenantPool
{
    public static function bootBelongsToTenantPool(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $builder->where(
                $builder->getModel()->qualifyColumn('tenant_id'),
                static::currentTenantKey(),
            );
        });

        static::creating(function (Model $model): void {
            $tenantKey = static::currentTenantKey();
            $current = $model->getAttribute('tenant_id');

            if ($current === null || $current === '') {
                $model->setAttribute('tenant_id', $tenantKey);

                return;
            }

            if ((string) $current !== $tenantKey) {
                throw new TenantContextMismatchException;
            }
        });
    }

    protected static function currentTenantKey(): string
    {
        if (! tenancy()->initialized || tenant() === null) {
            throw new TenantContextMissingException;
        }

        return (string) tenant()->getTenantKey();
    }
}
