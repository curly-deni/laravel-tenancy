# Laravel Tenancy

`curly-deni/laravel-tenancy` provides tenant identity, memberships, invitations,
team-based roles, and tenant-aware Eloquent resources for Laravel 13. It uses
`spatie/laravel-permission` for roles and permissions.

## Requirements

- PHP 8.4+
- Laravel 13
- `spatie/laravel-permission` 8.3+
- An authenticatable Eloquent user model using Spatie's `HasRoles` trait
- A bigint auto-incrementing user key

## Installation

Install the package and publish Spatie's configuration and migrations if they
are not already installed:

```sh
composer require curly-deni/laravel-tenancy
php artisan vendor:publish --provider="Spatie\\Permission\\PermissionServiceProvider" --tag="laravel-permission-config"
php artisan vendor:publish --provider="Spatie\\Permission\\PermissionServiceProvider" --tag="laravel-permission-migrations"
php artisan vendor:publish --tag="laravel-tenancy-migrations"
php artisan vendor:publish --tag="laravel-tenancy-config"
```

Enable Teams in `config/permission.php` before running Spatie's migration. Use
`tenant_id` as the team foreign key and the package resolver:

```php
use Aesis\Tenancy\Support\PlatformTeamResolver;

return [
    // Keep the other generated permission settings.
    'teams' => true,
    'column_names' => [
        // Keep other column names here.
        'team_foreign_key' => 'tenant_id',
    ],
    'team_resolver' => PlatformTeamResolver::class,
];
```

Set the user model and optionally table and user attribute names in
`config/tenancy.php`:

```php
return [
    'models' => ['user' => App\Models\User::class],
    'user' => ['email_column' => 'email', 'name_column' => 'name'],
    'tables' => [
        'tenants' => 'tenancy__tenants',
        'memberships' => 'tenancy__memberships',
        'invitations' => 'tenancy__invitations',
        'role_templates' => 'tenancy__role_templates',
    ],
];
```

`name_column` can be `null` if the user model has no display-name column. Run
Spatie's migration before the package migrations, then run the remaining
migrations normally. The package stubs are published with generated timestamps:

```sh
php artisan migrate --path=database/migrations/<generated_permission_migration>.php
php artisan migrate
```

The first tenancy migration creates tenants and memberships. The invitation
migration creates invitations. Later migrations create the base permissions
and role templates, and provision templates for existing tenants. Spatie's
tables must exist before the tenancy migrations run.

## Current tenant

The service provider binds `Aesis\Tenancy\Contracts\CurrentTenant` and registers
the `tenancy.resolve` and `tenancy.platform` middleware aliases. Apply
`tenancy.resolve` to routes that require an active tenant; it resolves the
tenant from `X-Tenant-Id`, checks the authenticated user's membership, enters
the tenant context, and sets Spatie's team id. Applications provide their own
routes, controllers, policies, invitation delivery, and response format.

Use the managers and authorization service directly:

```php
app(Aesis\Tenancy\Services\TenantManager::class)->createOrganization($user, 'Acme');

app(Aesis\Tenancy\Services\TenantAuthorization::class)
    ->allows($user, $tenant, 'tenant.view');
```

Listen for `Aesis\Tenancy\Events\TenantInvitationCreated` to deliver
invitations. Use `Aesis\Tenancy\Support\TenantTeamContext::run($tenant, $callback)`
when temporarily switching Spatie's team id; it restores the previous id after
the callback.

The initial role templates are `owner`, `admin`, `editor`, and `viewer`. New
tenants receive roles from the current templates. Existing roles are not
automatically reconciled when templates change; applications should update the
template and existing tenant roles in their own migration.

## Tenant resource isolation

Tenant-owned application models opt in with an attribute and trait:

```php
use Aesis\Tenancy\Attributes\TenantResource;
use Aesis\Tenancy\Models\Concerns\HasResourceTenancy;
use Illuminate\Database\Eloquent\Model;

#[TenantResource]
class Document extends Model
{
    use HasResourceTenancy;
}
```

`TenantResource` scopes reads to the active tenant and sets the tenant key on
create and Eloquent bulk insert/upsert operations. `HybridResource` also shows
shared rows with a `null` tenant key; tenant writes remain limited to rows owned
by that tenant. `CommonResource` adds no tenancy scope. Each attribute accepts a
custom tenant column, for example `#[TenantResource(column: 'workspace_id')]`.

The Eloquent protections do not cover raw SQL or query-builder writes, and
`withoutGlobalScopes()` bypasses read filtering. Keep policies in place for
authorization and constrain raw database access explicitly.

## Tests and analysis

```sh
composer install
composer test
composer analyse
composer format
```

## License

MIT. See [LICENSE.md](LICENSE.md).
