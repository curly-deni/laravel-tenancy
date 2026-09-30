<?php

namespace Aesis\Tenancy\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class HybridResource
{
    public function __construct(public string $column = 'tenant_id') {}
}
