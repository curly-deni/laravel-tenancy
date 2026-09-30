<?php

namespace Aesis\Tenancy\Services;

use Aesis\Tenancy\Enums\MembershipStatus;
use Aesis\Tenancy\Enums\TenantStatus;
use Aesis\Tenancy\Enums\TenantType;
use Aesis\Tenancy\Models\Tenant;
use Aesis\Tenancy\Models\TenantMembership;
use Aesis\Tenancy\Support\TenancyUsers;
use Aesis\Tenancy\Support\TenantRoleCatalog;
use Aesis\Tenancy\Support\TenantTeamContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Support\Config as PermissionConfig;

final class TenantManager
{
    public function ensurePersonalTenant(Model $user): Tenant
    {
        return DB::transaction(function () use ($user): Tenant {
            $tenant = Tenant::query()->firstOrCreate(
                ['owner_user_id' => $user->getKey()],
                [
                    'type' => TenantType::Personal,
                    'name' => $this->personalTenantName($user),
                    'created_by' => $user->getKey(),
                    'status' => TenantStatus::Active,
                ],
            );

            TenantMembership::query()->firstOrCreate(
                ['tenant_id' => $tenant->getKey(), 'user_id' => $user->getKey()],
                ['status' => MembershipStatus::Active, 'joined_at' => now()],
            );

            $this->ensureRoles($tenant);
            $this->assignRole($user, $tenant, 'owner');

            return $tenant->fresh(['memberships']);
        });
    }

    public function createOrganization(Model $owner, string $name): Tenant
    {
        return DB::transaction(function () use ($owner, $name): Tenant {
            $tenant = Tenant::query()->create([
                'type' => TenantType::Organization,
                'name' => trim($name),
                'created_by' => $owner->getKey(),
                'status' => TenantStatus::Active,
            ]);

            TenantMembership::query()->create([
                'tenant_id' => $tenant->getKey(),
                'user_id' => $owner->getKey(),
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);

            $this->ensureRoles($tenant);
            $this->assignRole($owner, $tenant, 'owner');

            return $tenant->fresh(['memberships']);
        });
    }

    public function ensureRoles(Tenant $tenant): void
    {
        TenantRoleCatalog::createForTenant($tenant);
    }

    public function delete(Tenant $tenant): void
    {
        abort_if($tenant->isPersonal(), 422, 'Личный tenant нельзя удалить из панели управления.');

        DB::transaction(fn () => $this->deleteTenantData($tenant));
    }

    public function deletePersonalTenant(Model $user): void
    {
        DB::transaction(function () use ($user): void {
            $personalTenant = Tenant::query()
                ->where('owner_user_id', $user->getKey())
                ->where('type', TenantType::Personal)
                ->first();

            if ($personalTenant !== null) {
                $this->deleteTenantData($personalTenant);
            }
        });
    }

    public function isLastOrganizationOwner(Model $user): bool
    {
        $tenantIds = TenantMembership::query()
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active)
            ->whereHas('tenant', fn ($query) => $query->where('type', TenantType::Organization->value))
            ->distinct()
            ->pluck('tenant_id');

        foreach (Tenant::query()->whereKey($tenantIds)->get() as $tenant) {
            $isLastOwner = TenantTeamContext::run($tenant, function () use ($tenant, $user): bool {
                $owners = TenantMembership::query()
                    ->where('tenant_id', $tenant->getKey())
                    ->where('status', MembershipStatus::Active)
                    ->whereHas('user.roles', fn ($query) => $query
                        ->where('name', 'owner')
                        ->where('guard_name', TenancyUsers::guardName()));

                return (clone $owners)->where('user_id', $user->getKey())->exists()
                    && $owners->count() === 1;
            });

            if ($isLastOwner) {
                return true;
            }
        }

        return false;
    }

    public function archive(Tenant $tenant): void
    {
        abort_if($tenant->isPersonal(), 422, 'Личный tenant нельзя архивировать.');

        DB::transaction(function () use ($tenant): void {
            $tenant = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            $tenant->forceFill(['status' => TenantStatus::Archived])->save();
        });
    }

    public function restoreArchived(Tenant $tenant): void
    {
        abort_if($tenant->isPersonal(), 422, 'Личный tenant нельзя восстановить из архива.');
        DB::transaction(function () use ($tenant): void {
            $tenant = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            abort_if($tenant->isPersonal(), 422, 'Личный tenant нельзя восстановить из архива.');
            abort_unless($tenant->status === TenantStatus::Archived, 422, 'Tenant не находится в архиве.');
            $tenant->forceFill(['status' => TenantStatus::Active])->save();
        });
    }

    private function assignRole(Model $user, Tenant $tenant, string $roleName): void
    {
        TenantTeamContext::run($tenant, function () use ($user, $roleName): void {
            if (! method_exists($user, 'assignRole') || ! method_exists($user, 'hasRole')) {
                throw new \LogicException('The configured tenancy user model must use Spatie HasRoles.');
            }
            $user->unsetRelation('roles')->unsetRelation('permissions');
            try {
                if (! $user->hasRole($roleName, TenancyUsers::guardName())) {
                    $user->assignRole($roleName);
                }
            } finally {
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }
        });
    }

    private function personalTenantName(Model $user): string
    {
        $name = trim(TenancyUsers::name($user));

        return $name !== '' ? $name : TenancyUsers::email($user);
    }

    private function deleteTenantData(Tenant $tenant): void
    {
        $teamKey = config('permission.column_names.team_foreign_key', 'team_id');
        $roleModel = PermissionConfig::roleModel();

        TenantTeamContext::run($tenant, function () use ($tenant, $teamKey, $roleModel): void {
            $roleModel::query()->where($teamKey, $tenant->getKey())->get()->each->delete();
        });

        $tenant->delete();
    }
}
