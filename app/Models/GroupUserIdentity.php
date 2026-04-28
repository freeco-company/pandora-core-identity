<?php

namespace App\Models;

use Database\Factories\GroupUserIdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $group_user_id UUID
 * @property string $type email / phone / google / line / apple
 * @property string $value
 * @property ?Carbon $verified_at
 * @property bool $is_primary
 * @property ?array<string, mixed> $raw_payload
 *
 * @method static GroupUserIdentityFactory factory($count = null, $state = [])
 */
class GroupUserIdentity extends Model
{
    /** @use HasFactory<GroupUserIdentityFactory> */
    use HasFactory;

    protected $table = 'group_user_identities';

    protected $fillable = [
        'group_user_id',
        'type',
        'value',
        'verified_at',
        'is_primary',
        'raw_payload',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'is_primary' => 'boolean',
        'raw_payload' => 'array',
    ];

    public const TYPE_EMAIL = 'email';

    public const TYPE_PHONE = 'phone';

    public const TYPE_GOOGLE = 'google';

    public const TYPE_LINE = 'line';

    public const TYPE_APPLE = 'apple';

    /**
     * @return BelongsTo<GroupUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(GroupUser::class, 'group_user_id');
    }
}
