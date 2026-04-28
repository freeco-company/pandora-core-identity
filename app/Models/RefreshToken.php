<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $group_user_id UUID
 * @property string $family_id UUID（同一條 refresh chain 共用）
 * @property string $token_hash SHA-256 hex
 * @property string $product_code
 * @property ?int $replaced_by_id
 * @property Carbon $expires_at
 * @property ?Carbon $used_at
 * @property ?Carbon $revoked_at
 * @property ?string $revoked_reason
 * @property ?string $issued_ip
 * @property ?string $issued_user_agent
 */
class RefreshToken extends Model
{
    protected $table = 'refresh_tokens';

    protected $fillable = [
        'group_user_id',
        'family_id',
        'token_hash',
        'product_code',
        'replaced_by_id',
        'expires_at',
        'used_at',
        'revoked_at',
        'revoked_reason',
        'issued_ip',
        'issued_user_agent',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public const REASON_ROTATED = 'rotated';

    public const REASON_REUSE_DETECTED = 'reuse_detected';

    public const REASON_LOGOUT = 'logout';

    public const REASON_ADMIN = 'admin';

    /**
     * @return BelongsTo<GroupUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(GroupUser::class, 'group_user_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->used_at === null
            && $this->expires_at->isFuture();
    }
}
