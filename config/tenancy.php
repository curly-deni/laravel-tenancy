<?php

return [
    'models' => [
        'user' => null,
    ],

    'user' => [
        'email_column' => 'email',
        'name_column' => 'name',
    ],

    'tables' => [
        'tenants' => 'tenancy__tenants',
        'memberships' => 'tenancy__memberships',
        'invitations' => 'tenancy__invitations',
        'role_templates' => 'tenancy__role_templates',
    ],
];
