<?php

namespace Aesis\Tenancy;

use Aesis\Tenancy\Console\Commands\EnsurePersonalTenants;
use Aesis\Tenancy\Contracts\CurrentTenant;
use Aesis\Tenancy\Http\Middleware\EnterPlatformContext;
use Aesis\Tenancy\Http\Middleware\ResolveTenant;
use Aesis\Tenancy\Services\TenantAuthorization;
use Aesis\Tenancy\Services\TenantContext;
use Aesis\Tenancy\Services\TenantManager;
use Illuminate\Routing\Router;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class TenancyServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-tenancy')
            ->hasConfigFile('tenancy')
            ->hasMigration('create_tenancy_tables')
            ->hasMigration('create_tenant_invitations')
            ->hasMigration('create_tenant_permissions')
            ->hasMigration('create_tenant_role_templates')
            ->hasCommand(EnsurePersonalTenants::class);
    }

    public function packageRegistered(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(CurrentTenant::class, fn ($app) => $app->make(TenantContext::class));
        $this->app->scoped(TenantAuthorization::class);
        $this->app->singleton(TenantManager::class);
    }

    public function packageBooted(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('tenancy.resolve', ResolveTenant::class);
        $this->app->make(Router::class)->aliasMiddleware('tenancy.platform', EnterPlatformContext::class);
    }
}
