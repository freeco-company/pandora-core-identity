<?php

namespace App\Services\Auth;

use App\Models\GroupUser;

/**
 * OAuth 登入處理結果 DTO。
 *
 * 三種狀態：
 *   - existing       已有 identity，直接登入
 *   - created        新使用者已建立並登入
 *   - merge_suggested 偵測到 email 衝突，回傳要求 client 確認合併
 */
class OAuthLoginResult
{
    public const EXISTING = 'existing';

    public const CREATED = 'created';

    public const MERGE_SUGGESTED = 'merge_suggested';

    /**
     * @param  array<string, mixed>  $pendingIdentity
     */
    private function __construct(
        public readonly string $status,
        public readonly GroupUser $user,
        public readonly array $pendingIdentity = [],
    ) {}

    public static function existing(GroupUser $user): self
    {
        return new self(self::EXISTING, $user);
    }

    public static function created(GroupUser $user): self
    {
        return new self(self::CREATED, $user);
    }

    /**
     * @param  array<string, mixed>  $pendingIdentity
     */
    public static function mergeSuggested(GroupUser $existingUser, array $pendingIdentity): self
    {
        return new self(self::MERGE_SUGGESTED, $existingUser, $pendingIdentity);
    }

    public function shouldIssueTokens(): bool
    {
        return $this->status !== self::MERGE_SUGGESTED;
    }
}
