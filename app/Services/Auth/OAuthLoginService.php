<?php

namespace App\Services\Auth;

use App\Models\GroupUser;
use App\Models\GroupUserIdentity;
use Illuminate\Support\Facades\DB;

/**
 * 處理 OAuth provider 登入後的 user lookup / create / 跨 provider 衝突偵測。
 *
 * 流程（ADR-001 §2.3）：
 *   1. (provider, provider_user_id) 已存在 → 直接登入該 user
 *   2. 不存在但 provider email 對到既有 group_user.email_canonical → 不自動合併，
 *      回傳 MergeSuggestion 給 client，由 client 走 confirm flow（之後 #5+ 實作）
 *   3. 全新 → create group_user + identity
 *
 * 「不自動合併」是刻意設計（ADR-001 §3.2 + 母艦 customer_merge_log 邏輯）：
 *   防止有人惡意用「我也有這個 email 的 OAuth」吃掉別人帳號。
 */
class OAuthLoginService
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function loginOrCreate(
        string $providerType,   // GroupUserIdentity::TYPE_GOOGLE / TYPE_LINE / TYPE_APPLE
        string $providerUserId,
        ?string $email = null,
        ?string $displayName = null,
        array $rawPayload = [],
    ): OAuthLoginResult {
        // Step 1: identity 已存在 → 直接登入
        $identity = GroupUserIdentity::where('type', $providerType)
            ->where('value', $providerUserId)
            ->first();

        if ($identity !== null) {
            $user = GroupUser::find($identity->group_user_id);
            if ($user === null) {
                throw new \RuntimeException('Identity points to non-existent user.');
            }

            // 更新 raw_payload (token rotated 等變動)
            $identity->raw_payload = $rawPayload;
            $identity->save();

            $user->last_login_at = now();
            $user->save();

            return OAuthLoginResult::existing($user);
        }

        // Step 2: 該 email 已被其他 user 使用 → 提示合併（不自動合併）
        $emailCanon = GroupUser::canonicalizeEmail($email);
        if ($emailCanon !== null) {
            $existingByEmail = GroupUser::where('email_canonical', $emailCanon)->first();
            if ($existingByEmail !== null) {
                return OAuthLoginResult::mergeSuggested(
                    $existingByEmail,
                    [
                        'provider' => $providerType,
                        'provider_user_id' => $providerUserId,
                        'email' => $email,
                        'display_name' => $displayName,
                    ]
                );
            }
        }

        // Step 3: 全新使用者 → create group_user + identity
        $user = DB::transaction(function () use ($providerType, $providerUserId, $emailCanon, $displayName, $rawPayload) {
            $user = GroupUser::create([
                'email_canonical' => $emailCanon,
                'display_name' => $displayName,
                'status' => 'active',
                'last_login_at' => now(),
            ]);

            GroupUserIdentity::create([
                'group_user_id' => $user->id,
                'type' => $providerType,
                'value' => $providerUserId,
                'verified_at' => now(),  // OAuth provider 已驗證
                'is_primary' => true,
                'raw_payload' => $rawPayload,
            ]);

            // 如果 OAuth 帶 email，也記一筆 email identity（標 verified）
            if ($emailCanon !== null) {
                GroupUserIdentity::firstOrCreate(
                    ['type' => GroupUserIdentity::TYPE_EMAIL, 'value' => $emailCanon],
                    [
                        'group_user_id' => $user->id,
                        'verified_at' => now(),
                        'is_primary' => true,
                    ]
                );
            }

            return $user;
        });

        return OAuthLoginResult::created($user);
    }
}
