<?php

namespace Aesis\Tenancy\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class TestUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];
}
