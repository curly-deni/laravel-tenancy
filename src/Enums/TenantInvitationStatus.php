<?php

namespace Aesis\Tenancy\Enums;

enum TenantInvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Revoked = 'revoked';
}
