<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

#[Fillable(['id', 'name', 'db_pool', 'is_active'])]
class Tenant extends BaseTenant
{
    /** @use HasFactory<TenantFactory> */
    use HasDomains, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'db_pool',
            'is_active',
            'created_at',
            'updated_at',
        ];
    }
}
