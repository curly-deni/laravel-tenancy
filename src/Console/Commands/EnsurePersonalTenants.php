<?php

namespace Aesis\Tenancy\Console\Commands;

use Aesis\Tenancy\Enums\TenantType;
use Aesis\Tenancy\Models\Tenant;
use Aesis\Tenancy\Services\TenantManager;
use Aesis\Tenancy\Support\TenancyUsers;
use Illuminate\Console\Command;

final class EnsurePersonalTenants extends Command
{
    protected $signature = 'tenancy:ensure-personal-tenants
        {--dry-run : Only report users without a personal tenant}';

    protected $description = 'Create missing personal tenants for users in the configured user model';

    public function handle(TenantManager $manager): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $checked = 0;
        $missing = 0;
        $created = 0;

        $users = TenancyUsers::query();
        $keyName = $users->getModel()->getKeyName();

        $users
            ->orderBy($keyName)
            ->chunkById(100, function ($users) use ($dryRun, $manager, &$checked, &$missing, &$created): void {
                foreach ($users as $user) {
                    $checked++;

                    $hasPersonalTenant = Tenant::query()
                        ->where('owner_user_id', $user->getKey())
                        ->where('type', TenantType::Personal->value)
                        ->exists();

                    if ($hasPersonalTenant) {
                        continue;
                    }

                    $missing++;

                    if (! $dryRun) {
                        $manager->ensurePersonalTenant($user);
                        $created++;
                    }
                }
            }, $keyName);

        if ($dryRun) {
            $this->info("Checked {$checked} user(s); {$missing} missing personal tenant(s).");

            return self::SUCCESS;
        }

        $this->info("Checked {$checked} user(s); created {$created} personal tenant(s).");

        return self::SUCCESS;
    }
}
