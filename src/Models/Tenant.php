<?php

namespace Aesis\Tenancy\Models;

use Aesis\Tenancy\Enums\TenantStatus;
use Aesis\Tenancy\Enums\TenantType;
use Aesis\Tenancy\Models\Concerns\UsesConfiguredTable;
use Aesis\Tenancy\Support\TenancyTables;
use Aesis\Tenancy\Support\TenancyUsers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Support\Config as PermissionConfig;

/**
 * @property int $id
 * @property TenantType $type
 * @property string $name
 * @property int|null $owner_user_id
 * @property int|null $created_by
 * @property TenantStatus $status
 */
class Tenant extends Model
{
    protected string $tenancyTableName = 'tenants';

    use UsesConfiguredTable;

    protected $fillable = [
        'type',
        'name',
        'owner_user_id',
        'created_by',
        'status',
    ];

    /** @return BelongsTo<Model, $this> */
    public function owner(): BelongsTo
    {
        $userModel = TenancyUsers::model();

        return $this->belongsTo($userModel, 'owner_user_id', (new $userModel)->getKeyName());
    }

    /** @return BelongsTo<Model, $this> */
    public function creator(): BelongsTo
    {
        $userModel = TenancyUsers::model();

        return $this->belongsTo($userModel, 'created_by', (new $userModel)->getKeyName());
    }

    /** @return HasMany<TenantMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    /** @return HasMany<TenantInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(TenantInvitation::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(PermissionConfig::roleModel(), config('permission.column_names.team_foreign_key', 'team_id'));
    }

    public function users(): BelongsToMany
    {
        $userModel = TenancyUsers::model();

        return $this->belongsToMany(
            $userModel,
            TenancyTables::get('memberships'),
            'tenant_id',
            'user_id',
            $this->getKeyName(),
            (new $userModel)->getKeyName(),
        )
            ->withPivot(['status', 'invited_by', 'joined_at'])
            ->withTimestamps();
    }

    public function isPersonal(): bool
    {
        return $this->type === TenantType::Personal;
    }

    protected function casts(): array
    {
        return [
            'type' => TenantType::class,
            'status' => TenantStatus::class,
        ];
    }
}
