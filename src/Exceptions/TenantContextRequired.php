<?php

namespace Aesis\Tenancy\Exceptions;

use RuntimeException;

final class TenantContextRequired extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self("A valid tenant context is required to access {$model}.");
    }
}
