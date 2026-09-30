<?php

namespace Aesis\Tenancy\Support;

use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Support\Config as PermissionConfig;

final class TenancyPermissionModels
{
    /** @return class-string<Role> */
    public static function role(): string
    {
        $model = PermissionConfig::roleModel();

        if (! is_a($model, Role::class, true)) {
            throw new InvalidArgumentException('The configured Spatie role model must extend Spatie\Permission\Models\Role.');
        }

        return $model;
    }

    /** @return class-string<Permission> */
    public static function permission(): string
    {
        $model = PermissionConfig::permissionModel();

        if (! is_a($model, Permission::class, true)) {
            throw new InvalidArgumentException('The configured Spatie permission model must extend Spatie\Permission\Models\Permission.');
        }

        return $model;
    }
}
