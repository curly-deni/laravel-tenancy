<?php

namespace Aesis\Tenancy\Services;

use Aesis\Tenancy\Contracts\CurrentTenant;
use Aesis\Tenancy\Models\Tenant;

final class TenantContext implements CurrentTenant
{
    private ?Tenant $tenant = null;

    private bool $platform = false;

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->platform = false;
    }

    public function enterPlatform(): void
    {
        $this->tenant = null;
        $this->platform = true;
    }

    public function isPlatform(): bool
    {
        return $this->platform;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->platform = false;
    }
}
