<?php

use Aesis\Tenancy\Contracts\CurrentTenant;
use Aesis\Tenancy\Enums\TenantStatus;
use Aesis\Tenancy\Enums\TenantType;
use Aesis\Tenancy\Exceptions\TenantResourceAccessDenied;
use Aesis\Tenancy\Models\Tenant;
use Aesis\Tenancy\Tests\Support\TenantDocument;

it('scopes records and bulk inserts to the active tenant', function (): void {
    $tenant = Tenant::query()->create([
        'type' => TenantType::Organization,
        'name' => 'Tenant One',
        'status' => TenantStatus::Active,
    ]);
    $otherTenant = Tenant::query()->create([
        'type' => TenantType::Organization,
        'name' => 'Tenant Two',
        'status' => TenantStatus::Active,
    ]);

    app(CurrentTenant::class)->set($tenant);

    TenantDocument::query()->insert([
        ['title' => 'Created for the active tenant'],
        ['title' => 'Also created for the active tenant'],
    ]);

    expect(TenantDocument::query()->pluck('title')->all())
        ->toBe(['Created for the active tenant', 'Also created for the active tenant']);

    expect(fn () => TenantDocument::query()->insert([
        'title' => 'Foreign row',
        'tenant_id' => $otherTenant->getKey(),
    ]))->toThrow(TenantResourceAccessDenied::class);
});

it('requires the tenant key in upsert conflict columns and protects it from conflict updates', function (): void {
    $tenant = Tenant::query()->create([
        'type' => TenantType::Organization,
        'name' => 'Tenant One',
        'status' => TenantStatus::Active,
    ]);

    app(CurrentTenant::class)->set($tenant);

    expect(fn () => TenantDocument::query()->upsert(
        ['title' => 'Document'],
        ['title'],
    ))->toThrow(TenantResourceAccessDenied::class);

    expect(fn () => TenantDocument::query()->upsert(
        ['title' => 'Document'],
        ['tenant_id', 'title'],
        ['tenant_id'],
    ))->toThrow(TenantResourceAccessDenied::class);
});
