<?php

namespace Aesis\Tenancy\Support;

use Aesis\Tenancy\Models\Tenant;
use Aesis\Tenancy\Models\TenantRoleTemplate;

final class TenantRoleCatalog
{
    public static function createForTenant(Tenant $tenant): void
    {
        $roleModel = TenancyPermissionModels::role();
        $permissionModel = TenancyPermissionModels::permission();
        $guard = TenancyUsers::guardName();
        $teamKey = config('permission.column_names.team_foreign_key', 'team_id');
        $templates = TenantRoleTemplate::query()->where('guard_name', $guard)->get();
        TenantTeamContext::run($tenant, function () use ($tenant, $roleModel, $permissionModel, $guard, $teamKey, $templates): void {
            foreach ($templates as $template) {
                $role = $roleModel::query()->firstOrCreate([
                    'name' => $template->name,
                    'guard_name' => $guard,
                    $teamKey => $tenant->getKey(),
                ]);
                if (! $role->wasRecentlyCreated) {
                    continue;
                }

                $permissionNames = $template->permission_names ?? [];
                $permissions = $permissionModel::query()
                    ->where('guard_name', $guard)
                    ->whereIn('name', $permissionNames)
                    ->get();

                if ($permissions->count() !== count($permissionNames)) {
                    throw new \LogicException("Role template [{$template->name}] references a missing permission.");
                }

                $role->givePermissionTo($permissions);
            }
        });
    }

    public static function removeUnusedRoles(): void
    {
        $roleModel = TenancyPermissionModels::role();
        $guard = TenancyUsers::guardName();
        $teamKey = config('permission.column_names.team_foreign_key', 'team_id');
        $templates = TenantRoleTemplate::query()
            ->where('guard_name', $guard)
            ->get()
            ->keyBy('name');

        Tenant::query()->orderBy('id')->chunkById(100, function ($tenants) use ($roleModel, $guard, $teamKey, $templates): void {
            foreach ($tenants as $tenant) {
                TenantTeamContext::run($tenant, function () use ($tenant, $roleModel, $guard, $teamKey, $templates): void {
                    $roleModel::query()
                        ->where($teamKey, $tenant->getKey())
                        ->where('guard_name', $guard)
                        ->whereIn('name', $templates->keys())
                        ->get()
                        ->each(function ($role) use ($templates): void {
                            $actualPermissions = $role->permissions()->pluck('name')->sort()->values()->all();
                            $expectedPermissions = collect($templates[$role->name]->permission_names)->sort()->values()->all();

                            if ($actualPermissions === $expectedPermissions && ! $role->users()->exists()) {
                                $role->delete();
                            }
                        });
                });
            }
        });
    }
}
