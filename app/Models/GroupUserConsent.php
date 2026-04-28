<?php

namespace App\Models;

use Database\Factories\GroupUserConsentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $group_user_id UUID
 * @property string $consent_type
 * @property string $version
 * @property Carbon $granted_at
 * @property ?Carbon $revoked_at
 * @property string $source
 * @property ?string $granted_ip
 * @property ?string $granted_user_agent
 *
 * @method static GroupUserConsentFactory factory($count = null, $state = [])
 */
class GroupUserConsent extends Model
{
    /** @use HasFactory<GroupUserConsentFactory> */
    use HasFactory;

    protected $table = 'group_user_consents';

    protected $fillable = [
        'group_user_id',
        'consent_type',
        'version',
        'granted_at',
        'revoked_at',
        'source',
        'granted_ip',
        'granted_user_agent',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public const TYPE_TOS = 'tos';

    public const TYPE_PRIVACY = 'privacy';

    public const TYPE_MARKETING_EMAIL = 'marketing_email';

    public const TYPE_DATA_EXPORT = 'data_export';

    /**
     * @return BelongsTo<GroupUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(GroupUser::class, 'group_user_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
