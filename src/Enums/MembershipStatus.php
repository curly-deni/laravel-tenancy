<?php

namespace Aesis\Tenancy\Enums;

enum MembershipStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Suspended = 'suspended';
}
