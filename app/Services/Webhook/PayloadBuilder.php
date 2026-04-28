<?php

namespace App\Services\Webhook;

use App\Models\GroupUser;
use App\Models\GroupUserIdentity;

/**
 * 把 GroupUser 序列化成 webhook payload。
 *
 * 統一格式給所有 consumer。consumer 端拿到後用 uuid upsert mirror。
 * 注意：email_canonical / phone_canonical 是去重 lookup key，display 用
 * 不需要 PII 的 consumer 應該只取 uuid + display_name + subscription_tier。
 */
class PayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(GroupUser $user): array
    {
        $user->loadMissing('identities');

        return [
            'uuid' => $user->id,
            'email_canonical' => $user->email_canonical,
            'phone_canonical' => $user->phone_canonical,
            'display_name' => $user->display_name,
            'gender' => $user->gender,
            'birthday' => $user->birthday?->toDateString(),
            'status' => $user->status,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'suspended_at' => $user->status === 'suspended' ? $user->updated_at?->toIso8601String() : null,
            'identities' => $user->identities->map(fn (GroupUserIdentity $i) => [
                'type' => $i->type,
                'value' => $i->value,
                'verified_at' => $i->verified_at?->toIso8601String(),
                'is_primary' => $i->is_primary,
            ])->values()->all(),
        ];
    }
}
