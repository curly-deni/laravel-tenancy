<?php

namespace Aesis\Tenancy\Tests\Support;

use Aesis\Tenancy\Attributes\TenantResource;
use Aesis\Tenancy\Models\Concerns\HasResourceTenancy;
use Illuminate\Database\Eloquent\Model;

#[TenantResource]
class TenantDocument extends Model
{
    use HasResourceTenancy;

    protected $table = 'tenant_documents';

    protected $guarded = [];
}
