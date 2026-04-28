<?php

namespace App\Services\Auth;

use App\Models\RefreshToken;

/**
 * Refresh token 簽發結果 DTO。
 *
 * - $plain: 唯一一次回給 client 的明文 token（之後 DB 只存 hash）
 * - $record: 對應 DB row（含 family_id / expires_at 等 metadata）
 */
class IssuedRefresh
{
    public function __construct(
        public readonly string $plain,
        public readonly RefreshToken $record,
    ) {}
}
