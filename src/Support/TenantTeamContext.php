<?php

namespace Aesis\Tenancy\Support;

use Aesis\Tenancy\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;

final class TenantTeamContext
{
    public static function run(Tenant|int|string $tenant, callable $callback): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($tenant instanceof Tenant ? $tenant->getKey() : $tenant);

        try {
            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }
}
