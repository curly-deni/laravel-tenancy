<?php

namespace Aesis\Tenancy\Enums;

enum TenantType: string
{
    case Personal = 'personal';
    case Organization = 'organization';
}
