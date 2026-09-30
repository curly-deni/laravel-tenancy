<?php

namespace Aesis\Tenancy\Contracts;

use Aesis\Tenancy\Models\Tenant;

interface CurrentTenant
{
    public function get(): ?Tenant;

    public function set(Tenant $tenant): void;

    public function enterPlatform(): void;

    public function isPlatform(): bool;

    public function clear(): void;
}
