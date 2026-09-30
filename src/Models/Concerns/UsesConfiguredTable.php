<?php

namespace Aesis\Tenancy\Models\Concerns;

use Aesis\Tenancy\Support\TenancyTables;

trait UsesConfiguredTable
{
    public function getTable(): string
    {
        return TenancyTables::get($this->tenancyTableName);
    }
}
