<?php

namespace Aesis\Tenancy\Tests;

use Aesis\Tenancy\TenancyServiceProvider;
use Aesis\Tenancy\Tests\Support\TestUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;
use Spatie\Permission\PermissionServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
            TenancyServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => TestUser::class,
        ]);
        $app['config']->set('tenancy.models.user', TestUser::class);
        $app['config']->set('permission.teams', true);
        $app['config']->set('permission.testing', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        $permissionProvider = new ReflectionClass(PermissionServiceProvider::class);
        $permissionMigration = dirname($permissionProvider->getFileName(), 2)
            .'/database/migrations/create_permission_tables.php.stub';
        (require $permissionMigration)->up();

        foreach (glob(__DIR__.'/../database/migrations/*.php.stub') as $migration) {
            (require $migration)->up();
        }

        Schema::create('tenant_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->index();
            $table->string('title');
            $table->timestamps();
        });
    }
}
