<?php

namespace Aesis\Tenancy\Services;

use Aesis\Tenancy\Enums\MembershipStatus;
use Aesis\Tenancy\Enums\TenantInvitationStatus;
use Aesis\Tenancy\Enums\TenantStatus;
use Aesis\Tenancy\Events\TenantInvitationCreated;
use Aesis\Tenancy\Models\Tenant;
use Aesis\Tenancy\Models\TenantInvitation;
use Aesis\Tenancy\Models\TenantMembership;
use Aesis\Tenancy\Support\TenancyUsers;
use Aesis\Tenancy\Support\TenantTeamContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Support\Config as PermissionConfig;

final class TenantMemberManager
{
    public function findInvitationForUser(string $token, Model $user): TenantInvitation
    {
        $invitation = TenantInvitation::query()
            ->with('tenant', 'inviter')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($invitation === null) {
            abort(404, 'Приглашение не найдено.');
        }

        $this->ensureInvitationCanBeHandled($invitation, $user);
        $this->ensureTenantIsActive($invitation->tenant);

        return $invitation;
    }

    public function invite(Tenant $tenant, string $email, array $roleNames, Model $actor, bool $allowOwnerOverride = false): TenantInvitation
    {
        $this->ensureOrganization($tenant);
        $this->ensureTenantIsActive($tenant);

        $email = mb_strtolower(trim($email));
        $user = TenancyUsers::queryByEmail($email)->first();

        if ($user !== null && TenantMembership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active)
            ->exists()) {
            abort(409, 'Пользователь уже состоит в этом tenant.');
        }

        $roles = $this->rolesForTenant($tenant, $roleNames);
        $this->ensureOwnerRoleCanBeManaged($tenant, $actor, $roles->pluck('name')->all(), allowOwnerOverride: $allowOwnerOverride);
        $token = Str::random(64);

        $invitation = DB::transaction(function () use ($tenant, $email, $user, $roles, $actor, $token): TenantInvitation {
            $lockedTenant = Tenant::query()
                ->whereKey($tenant->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureTenantIsActive($lockedTenant);

            if (TenantInvitation::query()
                ->where('tenant_id', $tenant->getKey())
                ->where('email', $email)
                ->where('status', TenantInvitationStatus::Pending)
                ->where('expires_at', '>', now())
                ->exists()) {
                abort(409, 'Для этого пользователя уже есть активное приглашение.');
            }

            return TenantInvitation::query()->create([
                'tenant_id' => $tenant->getKey(),
                'email' => $email,
                'user_id' => $user?->getKey(),
                'invited_by' => $actor->getKey(),
                'role_names' => $roles->pluck('name')->values()->all(),
                'token_hash' => hash('sha256', $token),
                'status' => TenantInvitationStatus::Pending,
                'expires_at' => now()->addDays(7),
            ]);
        })->load('tenant', 'inviter');

        event(new TenantInvitationCreated($invitation, $token, $email, $user));

        return $invitation;
    }

    public function accept(TenantInvitation $invitation, Model $user): TenantMembership
    {
        $membership = DB::transaction(function () use ($invitation, $user): ?TenantMembership {
            $invitation = TenantInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureInvitationIsPending($invitation);
            $this->ensureInvitationIsForUser($invitation, $user);

            if ($this->revokeIfExpired($invitation)) {
                return null;
            }

            $tenant = $invitation->tenant()->lockForUpdate()->firstOrFail();
            $this->ensureOrganization($tenant);
            $this->ensureTenantIsActive($tenant);

            if (TenantMembership::query()
                ->where('tenant_id', $tenant->getKey())
                ->where('user_id', $user->getKey())
                ->where('status', MembershipStatus::Active)
                ->exists()) {
                abort(409, 'Пользователь уже состоит в этом tenant.');
            }

            $roles = $this->rolesForTenant($tenant, $invitation->role_names ?? []);
            $membership = TenantMembership::query()->create([
                'tenant_id' => $tenant->getKey(),
                'user_id' => $user->getKey(),
                'status' => MembershipStatus::Active,
                'invited_by' => $invitation->invited_by,
                'joined_at' => now(),
            ]);

            $this->syncRoles($user, $tenant, $roles);
            $invitation->forceFill([
                'user_id' => $user->getKey(),
                'status' => TenantInvitationStatus::Accepted,
                'accepted_at' => now(),
            ])->save();

            return $membership->load('tenant', 'user');
        });

        if ($membership === null) {
            abort(410, 'Срок действия приглашения истёк.');
        }

        return $membership;
    }

    public function decline(TenantInvitation $invitation, Model $user): void
    {
        $expired = DB::transaction(function () use ($invitation, $user): bool {
            $invitation = TenantInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureInvitationIsPending($invitation);
            $this->ensureInvitationIsForUser($invitation, $user);

            if ($this->revokeIfExpired($invitation)) {
                return true;
            }

            $tenant = $invitation->tenant()->lockForUpdate()->firstOrFail();
            $this->ensureTenantIsActive($tenant);
            $invitation->forceFill([
                'user_id' => $user->getKey(),
                'status' => TenantInvitationStatus::Declined,
                'declined_at' => now(),
            ])->save();

            return false;
        });

        if ($expired) {
            abort(410, 'Срок действия приглашения истёк.');
        }
    }

    public function revoke(Tenant $tenant, TenantInvitation $invitation): void
    {
        DB::transaction(function () use ($tenant, $invitation): void {
            $tenant = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureTenantIsActive($tenant);
            $invitation = TenantInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $invitation->tenant_id !== (int) $tenant->getKey() || $invitation->status !== TenantInvitationStatus::Pending) {
                abort(404, 'Приглашение не найдено.');
            }

            $invitation->forceFill(['status' => TenantInvitationStatus::Revoked])->save();
        });
    }

    /**
     * @param  list<string>  $roleNames
     */
    public function add(Tenant $tenant, string $email, array $roleNames, Model $actor, bool $allowOwnerOverride = false): TenantMembership
    {
        $this->ensureOrganization($tenant);
        $this->ensureTenantIsActive($tenant);

        $user = TenancyUsers::queryByEmail($email)->firstOrFail();

        if (TenantMembership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->exists()) {
            abort(409, 'Пользователь уже состоит в этом tenant.');
        }

        $roles = $this->rolesForTenant($tenant, $roleNames);
        $this->ensureOwnerRoleCanBeManaged($tenant, $actor, $roles->pluck('name')->all(), allowOwnerOverride: $allowOwnerOverride);

        return DB::transaction(function () use ($tenant, $user, $roles, $actor): TenantMembership {
            $tenant = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureTenantIsActive($tenant);
            $membership = TenantMembership::query()->create([
                'tenant_id' => $tenant->getKey(),
                'user_id' => $user->getKey(),
                'status' => MembershipStatus::Active,
                'invited_by' => $actor->getKey(),
                'joined_at' => now(),
            ]);

            $this->syncRoles($user, $tenant, $roles);

            return $membership->load('tenant', 'user');
        });
    }

    /**
     * @param  list<string>  $roleNames
     */
    public function updateRoles(Tenant $tenant, TenantMembership $membership, array $roleNames, Model $actor, bool $allowOwnerOverride = false): TenantMembership
    {
        return DB::transaction(function () use ($tenant, $membership, $roleNames, $actor, $allowOwnerOverride): TenantMembership {
            $tenant = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            $membership = TenantMembership::query()->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();

            $this->ensureOrganization($tenant);
            $this->ensureTenantIsActive($tenant);
            $this->ensureMembershipBelongsToTenant($tenant, $membership);

            $roles = $this->rolesForTenant($tenant, $roleNames);
            $this->ensureOwnerRoleCanBeManaged(
                $tenant,
                $actor,
                $roles->pluck('name')->all(),
                $membership,
                allowOwnerOverride: $allowOwnerOverride,
            );

            if ($this->hasOwnerRole($membership) && ! $roles->contains('name', 'owner') && $this->ownerCount($tenant) <= 1) {
                abort(422, 'В tenant должен остаться хотя бы один owner.');
            }

            $this->syncRoles($membership->load('user')->user, $tenant, $roles);

            return $membership->fresh(['tenant', 'user']);
        });
    }

    public function remove(Tenant $tenant, TenantMembership $membership, Model $actor, bool $allowOwnerOverride = false): void
    {
        DB::transaction(function () use ($tenant, $membership, $actor, $allowOwnerOverride): void {
            $tenant = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            $membership = TenantMembership::query()->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();

            $this->ensureOrganization($tenant);
            $this->ensureTenantIsActive($tenant);
            $this->ensureMembershipBelongsToTenant($tenant, $membership);

            if ($this->hasOwnerRole($membership)) {
                if (! $allowOwnerOverride) {
                    $this->ensureActorIsOwner($tenant, $actor);
                }

                if ($this->ownerCount($tenant) <= 1) {
                    abort(422, 'Нельзя удалить последнего owner из tenant.');
                }
            }

            $this->syncRoles($membership->load('user')->user, $tenant, collect());
            $membership->delete();
        });
    }

    /**
     * @param  list<string>  $roleNames
     * @return Collection<int, Model>
     */
    private function rolesForTenant(Tenant $tenant, array $roleNames): Collection
    {
        $roleNames = array_values(array_unique($roleNames));
        $roleModel = PermissionConfig::roleModel();
        $roles = $roleModel::query()
            ->where(config('permission.column_names.team_foreign_key', 'team_id'), $tenant->getKey())
            ->where('guard_name', TenancyUsers::guardName())
            ->whereIn('name', $roleNames)
            ->orderBy('id')
            ->get();

        if ($roles->count() !== count($roleNames)) {
            abort(422, 'Одна или несколько ролей недоступны в этом tenant.');
        }

        return $roles;
    }

    /**
     * @param  Collection<int, Model>  $roles
     */
    private function syncRoles(Model $user, Tenant $tenant, Collection $roles): void
    {
        if (! method_exists($user, 'syncRoles')) {
            throw new \LogicException('The configured tenancy user model must use Spatie HasRoles.');
        }

        TenantTeamContext::run($tenant, function () use ($user, $roles): void {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            try {
                $user->syncRoles($roles->all());
            } finally {
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }
        });
    }

    /**
     * @param  list<string>  $roleNames
     */
    private function ensureOwnerRoleCanBeManaged(
        Tenant $tenant,
        Model $actor,
        array $roleNames,
        ?TenantMembership $membership = null,
        bool $allowOwnerOverride = false,
    ): void {
        if ($allowOwnerOverride) {
            return;
        }

        $changesOwnerRole = in_array('owner', $roleNames, true) || ($membership !== null && $this->hasOwnerRole($membership));

        if ($changesOwnerRole) {
            $this->ensureActorIsOwner($tenant, $actor);
        }
    }

    private function ensureActorIsOwner(Tenant $tenant, Model $actor): void
    {
        if (! $this->userHasRoleInTenant($tenant, $actor, 'owner')) {
            abort(403, 'Только owner может управлять ролью owner.');
        }
    }

    private function userHasRoleInTenant(Tenant $tenant, Model $user, string $roleName): bool
    {
        return TenantTeamContext::run($tenant, fn (): bool => $user::query()
            ->whereKey($user->getKey())
            ->whereHas('roles', fn ($query) => $query
                ->where('name', $roleName)
                ->where('guard_name', TenancyUsers::guardName()))
            ->exists());
    }

    private function hasOwnerRole(TenantMembership $membership): bool
    {
        return in_array('owner', $membership->roleNames(), true);
    }

    private function ownerCount(Tenant $tenant): int
    {
        return TenantTeamContext::run($tenant, fn (): int => TenantMembership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('status', MembershipStatus::Active)
            ->whereHas('user.roles', fn ($query) => $query
                ->where('name', 'owner')
                ->where('guard_name', TenancyUsers::guardName()))
            ->count());
    }

    private function ensureOrganization(Tenant $tenant): void
    {
        if ($tenant->isPersonal()) {
            abort(422, 'Личный tenant не поддерживает управление участниками.');
        }
    }

    private function ensureTenantIsActive(Tenant $tenant): void
    {
        if ($tenant->status !== TenantStatus::Active) {
            abort(410, 'Рабочее пространство больше недоступно.');
        }
    }

    private function ensureInvitationCanBeHandled(TenantInvitation $invitation, Model $user): void
    {
        $this->ensureInvitationIsPending($invitation);

        if ($this->revokeIfExpired($invitation)) {
            abort(410, 'Срок действия приглашения истёк.');
        }

        $this->ensureInvitationIsForUser($invitation, $user);
    }

    private function ensureInvitationIsPending(TenantInvitation $invitation): void
    {
        if ($invitation->status !== TenantInvitationStatus::Pending) {
            abort(410, 'Приглашение больше недействительно.');
        }
    }

    private function ensureInvitationIsForUser(TenantInvitation $invitation, Model $user): void
    {
        if (mb_strtolower(TenancyUsers::email($user)) !== mb_strtolower((string) $invitation->email)
            || ($invitation->user_id !== null && (string) $invitation->user_id !== (string) $user->getKey())) {
            abort(403, 'Это приглашение предназначено для другого пользователя.');
        }
    }

    private function revokeIfExpired(TenantInvitation $invitation): bool
    {
        if ($invitation->expires_at !== null && ! $invitation->expires_at->isPast()) {
            return false;
        }

        $invitation->forceFill(['status' => TenantInvitationStatus::Revoked])->save();

        return true;
    }

    private function ensureMembershipBelongsToTenant(Tenant $tenant, TenantMembership $membership): void
    {
        if ((int) $membership->tenant_id !== (int) $tenant->getKey() || $membership->status !== MembershipStatus::Active) {
            abort(404, 'Участник не найден.');
        }
    }
}
