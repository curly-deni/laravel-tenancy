<?php

namespace Aesis\Tenancy\Events;

use Aesis\Tenancy\Models\TenantInvitation;
use Illuminate\Database\Eloquent\Model;

final class TenantInvitationCreated
{
    public function __construct(
        public readonly TenantInvitation $invitation,
        public readonly string $token,
        public readonly string $email,
        public readonly ?Model $user,
    ) {}
}
