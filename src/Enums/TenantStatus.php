<?php

namespace Aesis\Tenancy\Enums;

enum TenantStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
