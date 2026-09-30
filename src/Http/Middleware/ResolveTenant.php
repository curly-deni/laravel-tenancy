<?php

namespace Aesis\Tenancy\Http\Middleware;

use Aesis\Tenancy\Contracts\CurrentTenant;
use Aesis\Tenancy\Enums\MembershipStatus;
use Aesis\Tenancy\Enums\TenantStatus;
use Aesis\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ResolveTenant
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        abort_unless($user !== null, 401);
        $tenantId = $request->header('X-Tenant-Id');
        if ($tenantId === null || filter_var($tenantId, FILTER_VALIDATE_INT) === false || (int) $tenantId < 1) {
            throw new BadRequestHttpException('Необходимо указать корректный заголовок X-Tenant-Id.');
        }

        $tenant = Tenant::query()
            ->whereKey((int) $tenantId)
            ->where('status', TenantStatus::Active->value)
            ->whereHas('memberships', function ($query) use ($user): void {
                $query->where('user_id', $user->getAuthIdentifier())
                    ->where('status', MembershipStatus::Active->value);
            })
            ->first();

        if ($tenant === null) {
            throw new NotFoundHttpException('Tenant не найден.');
        }

        $context = app(CurrentTenant::class);
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $context->set($tenant);
        $registrar->setPermissionsTeamId($tenant->getKey());
        $this->clearPermissionRelations($user);

        try {
            foreach ($request->route()?->parameters() ?? [] as $parameter) {
                if (! $parameter instanceof Model || ! array_key_exists('tenant_id', $parameter->getAttributes())) {
                    continue;
                }

                $recordTenantId = $parameter->getAttribute('tenant_id');
                if ($recordTenantId !== null && (int) $recordTenantId !== (int) $tenant->getKey()) {
                    throw new NotFoundHttpException('Запись не найдена.');
                }
            }

            return $next($request);
        } finally {
            $context->clear();
            $registrar->setPermissionsTeamId($previousTeamId ?? 0);
            $this->clearPermissionRelations($user);
        }
    }

    private function clearPermissionRelations(Authenticatable $user): void
    {
        if ($user instanceof Model) {
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
