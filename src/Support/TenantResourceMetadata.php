<?php

namespace Aesis\Tenancy\Support;

use Aesis\Tenancy\Attributes\CommonResource;
use Aesis\Tenancy\Attributes\HybridResource;
use Aesis\Tenancy\Attributes\TenantResource;
use LogicException;
use ReflectionClass;

final class TenantResourceMetadata
{
    /** @var array<class-string, TenantResource|HybridResource|CommonResource> */
    private static array $attributes = [];

    /** @param class-string $modelClass */
    public static function for(string $modelClass): TenantResource|HybridResource|CommonResource
    {
        if (isset(self::$attributes[$modelClass])) {
            return self::$attributes[$modelClass];
        }

        $reflection = new ReflectionClass($modelClass);
        $attributes = [
            ...$reflection->getAttributes(TenantResource::class),
            ...$reflection->getAttributes(HybridResource::class),
            ...$reflection->getAttributes(CommonResource::class),
        ];

        if (count($attributes) !== 1) {
            throw new LogicException(sprintf(
                '%s must declare exactly one tenancy resource attribute when using HasResourceTenancy.',
                $modelClass,
            ));
        }

        return self::$attributes[$modelClass] = $attributes[0]->newInstance();
    }
}
