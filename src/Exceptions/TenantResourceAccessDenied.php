<?php

namespace Aesis\Tenancy\Exceptions;

use RuntimeException;

final class TenantResourceAccessDenied extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self("The current tenant cannot access or change this {$model} record.");
    }
}
