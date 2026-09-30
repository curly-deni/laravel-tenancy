<?php

namespace Aesis\Tenancy\Support;

final class TenancyTables
{
    /** @var array<string, string> */
    private const DEFAULTS = [
        'tenants' => 'tenancy__tenants',
        'memberships' => 'tenancy__memberships',
        'invitations' => 'tenancy__invitations',
        'role_templates' => 'tenancy__role_templates',
    ];

    public static function get(string $name): string
    {
        return (string) config('tenancy.tables.'.$name, self::DEFAULTS[$name] ?? throw new \InvalidArgumentException("Unknown tenancy table [{$name}]."));
    }
}
