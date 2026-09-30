<?php

namespace Aesis\Tenancy\Support;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

final class PlatformTeamResolver implements PermissionsTeamResolver
{
    private int|string|null $teamId = 0;

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        $this->teamId = $id instanceof Model ? $id->getKey() : $id;
    }

    public function getPermissionsTeamId(): int|string|null
    {
        return $this->teamId;
    }
}
