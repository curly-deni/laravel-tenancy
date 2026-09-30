<?php

namespace Aesis\Tenancy\Services;

use Aesis\Tenancy\Enums\MembershipStatus;
use Aesis\Tenancy\Enums\TenantStatus;
use Aesis\Tenancy\Models\Tenant;
use Aesis\Tenancy\Models\TenantMembership;
use Aesis\Tenancy\Support\TenantTeamContext;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Tenant authorization backed by Spatie's role and permission tables.
 * Results are cached only for this scoped service instance/request.
 */
final class TenantAuthorization
{
    /** @var array<string, TenantMembership|null> */
    private array $membershipChecks = [];

    /** @var array<string, array<string, true>> */
    private array $permissionSets = [];

    public function allows(Authenticatable $user, Tenant $tenant, string $permission): bool
    {
        if ($tenant->status !== TenantStatus::Active) {
            return false;
        }

        $membership = $this->membership($user, $tenant);

        return $membership !== null && isset($this->permissionSet($membership)[$permission]);
    }

    /** @return list<string> */
    public function permissions(Authenticatable $user, Tenant $tenant): array
    {
        if ($tenant->status !== TenantStatus::Active) {
            return [];
        }

        $membership = $this->membership($user, $tenant);

        return $membership === null ? [] : array_keys($this->permissionSet($membership));
    }

    private function membership(Authenticatable $user, Tenant $tenant): ?TenantMembership
    {
        $id = (string) $user->getAuthIdentifier();
        $key = $tenant->getKey().':'.$id;

        if (! array_key_exists($key, $this->membershipChecks)) {
            $this->membershipChecks[$key] = TenantMembership::query()
                ->where('tenant_id', $tenant->getKey())
                ->where('user_id', $id)
                ->where('status', MembershipStatus::Active->value)
                ->first();
        }

        return $this->membershipChecks[$key];
    }

    /** @return array<string, true> */
    private function permissionSet(TenantMembership $membership): array
    {
        $key = $membership->tenant_id.':'.$membership->user_id;

        if (isset($this->permissionSets[$key])) {
            return $this->permissionSets[$key];
        }

        $user = $membership->loadMissing('user')->user;
        if ($user === null) {
            return $this->permissionSets[$key] = [];
        }
        if (! method_exists($user, 'getPermissionsViaRoles')) {
            throw new \LogicException('The configured tenancy user model must use Spatie HasRoles.');
        }

        $permissions = TenantTeamContext::run($membership->tenant_id, function () use ($user): array {
            $user->unsetRelation('roles')->unsetRelation('permissions');

            try {
                return $user->getPermissionsViaRoles()->pluck('name')->unique()->all();
            } finally {
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }
        });

        return $this->permissionSets[$key] = array_fill_keys($permissions, true);
    }
}
