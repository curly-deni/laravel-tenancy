<?php

namespace Aesis\Tenancy\Models;

use Aesis\Tenancy\Models\Concerns\UsesConfiguredTable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property list<string> $permission_names
 */
class TenantRoleTemplate extends Model
{
    protected string $tenancyTableName = 'role_templates';

    use UsesConfiguredTable;

    protected $fillable = [
        'name',
        'guard_name',
        'permission_names',
    ];

    protected function casts(): array
    {
        return [
            'permission_names' => 'array',
        ];
    }
}
