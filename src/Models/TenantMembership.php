<?php

namespace Aesis\Tenancy\Models;

use Aesis\Tenancy\Enums\MembershipStatus;
use Aesis\Tenancy\Models\Concerns\UsesConfiguredTable;
use Aesis\Tenancy\Support\TenancyUsers;
use Aesis\Tenancy\Support\TenantTeamContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property MembershipStatus $status
 * @property int|null $invited_by
 * @property Carbon|null $joined_at
 */
class TenantMembership extends Model
{
    protected string $tenancyTableName = 'memberships';

    use UsesConfiguredTable;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'status',
        'invited_by',
        'joined_at',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        $userModel = TenancyUsers::model();

        return $this->belongsTo($userModel, 'user_id', (new $userModel)->getKeyName());
    }

    /** @return BelongsTo<Model, $this> */
    public function inviter(): BelongsTo
    {
        $userModel = TenancyUsers::model();

        return $this->belongsTo($userModel, 'invited_by', (new $userModel)->getKeyName());
    }

    /**
     * @return list<string>
     */
    public function roleNames(): array
    {
        $user = $this->user;
        if ($user === null) {
            return [];
        }
        if (! method_exists($user, 'getRoleNames')) {
            throw new \LogicException('The configured tenancy user model must use Spatie HasRoles.');
        }

        return TenantTeamContext::run($this->tenant_id, function () use ($user): array {
            $user->unsetRelation('roles')->unsetRelation('permissions');

            try {
                return $user->getRoleNames()->sort()->values()->all();
            } finally {
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }
        });
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        $user = $this->user;
        if ($user === null) {
            return [];
        }
        if (! method_exists($user, 'getPermissionsViaRoles')) {
            throw new \LogicException('The configured tenancy user model must use Spatie HasRoles.');
        }

        return TenantTeamContext::run($this->tenant_id, function () use ($user): array {
            $user->unsetRelation('roles')->unsetRelation('permissions');

            try {
                return $user->getPermissionsViaRoles()
                    ->pluck('name')
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
            } finally {
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
        ];
    }
}
