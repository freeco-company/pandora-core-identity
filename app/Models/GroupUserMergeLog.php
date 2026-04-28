<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $surviving_group_user_id UUID
 * @property string $absorbed_group_user_id UUID
 * @property ?string $absorbed_email
 * @property ?string $absorbed_phone
 * @property ?array<string, mixed> $absorbed_identities
 * @property string $reason
 * @property ?array<string, mixed> $snapshot
 * @property ?string $actor
 */
class GroupUserMergeLog extends Model
{
    protected $table = 'group_user_merge_log';

    protected $fillable = [
        'surviving_group_user_id',
        'absorbed_group_user_id',
        'absorbed_email',
        'absorbed_phone',
        'absorbed_identities',
        'reason',
        'snapshot',
        'actor',
    ];

    protected $casts = [
        'absorbed_identities' => 'array',
        'snapshot' => 'array',
    ];
}
