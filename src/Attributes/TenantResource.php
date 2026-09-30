<?php

namespace Aesis\Tenancy\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TenantResource
{
    public function __construct(public string $column = 'tenant_id') {}
}
