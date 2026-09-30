<?php

use Aesis\Tenancy\Enums\MembershipStatus;
use Aesis\Tenancy\Enums\TenantInvitationStatus;
use Aesis\Tenancy\Enums\TenantType;
use Aesis\Tenancy\Models\Tenant;
use Aesis\Tenancy\Models\TenantInvitation;
use Aesis\Tenancy\Services\TenantManager;
use Aesis\Tenancy\Services\TenantMemberManager;
use Aesis\Tenancy\Support\TenantTeamContext;
use Aesis\Tenancy\Tests\Support\TestUser;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('creates a personal tenant and assigns its owner role to the configured user model', function (): void {
    $user = TestUser::query()->create([
        'name' => 'Package Test User',
        'email' => 'package-user@example.test',
        'password' => 'password',
    ]);

    $tenant = app(TenantManager::class)->ensurePersonalTenant($user);
    $membership = $tenant->memberships()->firstOrFail();

    expect($tenant->type)->toBe(TenantType::Personal)
        ->and($membership->roleNames())->toBe(['owner'])
        ->and(TenantTeamContext::run($tenant, fn (): bool => $user->hasPermissionTo('tenant.view')))->toBeTrue();
});

it('creates tenant-owned tables and default role templates from package migrations', function (): void {
    expect(Schema::hasTable('tenancy__tenants'))->toBeTrue()
        ->and(Schema::hasTable('tenancy__memberships'))->toBeTrue()
        ->and(Schema::hasTable('tenancy__invitations'))->toBeTrue()
        ->and(Schema::hasTable('tenancy__role_templates'))->toBeTrue();

    $this->assertDatabaseHas('tenancy__role_templates', [
        'name' => 'owner',
        'guard_name' => 'web',
    ]);
});

it('commits revocation before rejecting an expired invitation', function (): void {
    $user = TestUser::query()->create([
        'name' => 'Package Test User',
        'email' => 'package-user@example.test',
        'password' => 'password',
    ]);
    $tenant = Tenant::query()->create([
        'type' => TenantType::Organization,
        'name' => 'Package Test Tenant',
    ]);
    $invitation = TenantInvitation::query()->create([
        'tenant_id' => $tenant->getKey(),
        'email' => $user->email,
        'role_names' => ['viewer'],
        'token_hash' => hash('sha256', 'expired-token'),
        'status' => 'pending',
        'expires_at' => now()->subMinute(),
    ]);

    expect(fn () => app(TenantMemberManager::class)->accept($invitation, $user))
        ->toThrow(HttpException::class);

    $this->assertDatabaseHas('tenancy__invitations', [
        'id' => $invitation->getKey(),
        'status' => 'revoked',
    ]);
});

it('accepts a valid invitation and assigns its tenant roles', function (): void {
    $owner = TestUser::query()->create([
        'name' => 'Tenant Owner',
        'email' => 'owner@example.test',
        'password' => 'password',
    ]);
    $invitee = TestUser::query()->create([
        'name' => 'Tenant Member',
        'email' => 'member@example.test',
        'password' => 'password',
    ]);
    $tenant = app(TenantManager::class)->createOrganization($owner, 'Package Test Tenant');
    $invitation = TenantInvitation::query()->create([
        'tenant_id' => $tenant->getKey(),
        'email' => $invitee->email,
        'user_id' => $invitee->getKey(),
        'invited_by' => $owner->getKey(),
        'role_names' => ['viewer'],
        'token_hash' => hash('sha256', 'valid-token'),
        'status' => TenantInvitationStatus::Pending,
        'expires_at' => now()->addDay(),
    ]);

    $membership = app(TenantMemberManager::class)->accept($invitation, $invitee);

    expect($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->roleNames())->toBe(['viewer'])
        ->and($invitation->fresh()->status)->toBe(TenantInvitationStatus::Accepted);
});
