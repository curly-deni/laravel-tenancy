<?php

namespace Aesis\Tenancy\Models;

use Aesis\Tenancy\Enums\TenantInvitationStatus;
use Aesis\Tenancy\Models\Concerns\UsesConfiguredTable;
use Aesis\Tenancy\Support\TenancyUsers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $email
 * @property int|null $user_id
 * @property int|null $invited_by
 * @property list<string>|null $role_names
 * @property TenantInvitationStatus $status
 * @property string $token_hash
 * @property Carbon|null $expires_at
 */
class TenantInvitation extends Model
{
    protected string $tenancyTableName = 'invitations';

    use UsesConfiguredTable;

    protected $fillable = [
        'tenant_id',
        'email',
        'user_id',
        'invited_by',
        'role_names',
        'token_hash',
        'status',
        'expires_at',
        'accepted_at',
        'declined_at',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        $userModel = TenancyUsers::model();

        return $this->belongsTo($userModel, 'user_id', (new $userModel)->getKeyName());
    }

    /** @return BelongsTo<Model, $this> */
    public function inviter(): BelongsTo
    {
        $userModel = TenancyUsers::model();

        return $this->belongsTo($userModel, 'invited_by', (new $userModel)->getKeyName());
    }

    protected function casts(): array
    {
        return [
            'role_names' => 'array',
            'status' => TenantInvitationStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }
}
