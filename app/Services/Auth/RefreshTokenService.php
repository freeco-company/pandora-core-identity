<?php

namespace App\Services\Auth;

use App\Models\GroupUser;
use App\Models\RefreshToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Refresh token issue + rotation + reuse detection。
 *
 * 規則（OAuth 2.1）：
 *   - 每次 refresh：舊 token 標 used_at + revoked_reason='rotated'，發新 token，
 *     family_id 不變
 *   - 重用偵測：用 used_at 不為 null 的 token 來換 → 整條 family 全部 revoke
 *   - 過期 / 已被 revoke 的 token：直接拒絕，不引發 family revoke（避免 DOS 自己的 family）
 */
class RefreshTokenService
{
    public function issue(GroupUser $user, string $productCode, ?string $familyId = null, ?string $ip = null, ?string $ua = null): IssuedRefresh
    {
        $plain = $this->generatePlainToken();
        $hash = $this->hash($plain);

        $token = new RefreshToken;
        $token->group_user_id = $user->id;
        $token->family_id = $familyId ?? (string) Uuid::v7();
        $token->token_hash = $hash;
        $token->product_code = $productCode;
        $token->expires_at = now()->addSeconds((int) config('pandora_jwt.refresh_ttl'));
        $token->issued_ip = $ip;
        $token->issued_user_agent = $ua;
        $token->save();

        return new IssuedRefresh($plain, $token);
    }

    /**
     * 用一個 plain refresh token 換新的 access + refresh。
     *
     * @throws RuntimeException if invalid / expired / already-used (family revoked)
     */
    public function rotate(string $plain, string $productCode, ?string $ip = null, ?string $ua = null): IssuedRefresh
    {
        $hash = $this->hash($plain);

        // 1. 偵測階段（讀 + 標準錯誤）— 用獨立 transaction，避免 throw 把
        //    後續 family revoke 的寫入也一併 rollback
        $reuseFamilyId = null;

        try {
            return DB::transaction(function () use ($hash, $productCode, $ip, $ua, &$reuseFamilyId) {
                /** @var ?RefreshToken $token */
                $token = RefreshToken::where('token_hash', $hash)->lockForUpdate()->first();

                if ($token === null) {
                    throw new RuntimeException('Refresh token not found.');
                }

                if ($token->product_code !== $productCode) {
                    throw new RuntimeException('Refresh token does not match requested product.');
                }

                if ($token->used_at !== null || $token->revoked_at !== null) {
                    // 標記 family 待 revoke（在 transaction 外執行，避免 throw rollback）
                    $reuseFamilyId = $token->family_id;
                    throw new RuntimeException('Refresh token reuse detected; family revoked.');
                }

                if ($token->expires_at->isPast()) {
                    throw new RuntimeException('Refresh token expired.');
                }

                // 發新 token（同 family）
                $newPlain = $this->generatePlainToken();
                $new = new RefreshToken;
                $new->group_user_id = $token->group_user_id;
                $new->family_id = $token->family_id;
                $new->token_hash = $this->hash($newPlain);
                $new->product_code = $productCode;
                $new->expires_at = now()->addSeconds((int) config('pandora_jwt.refresh_ttl'));
                $new->issued_ip = $ip;
                $new->issued_user_agent = $ua;
                $new->save();

                $token->used_at = now();
                $token->revoked_at = now();
                $token->revoked_reason = RefreshToken::REASON_ROTATED;
                $token->replaced_by_id = $new->id;
                $token->save();

                return new IssuedRefresh($newPlain, $new);
            });
        } catch (RuntimeException $e) {
            if ($reuseFamilyId !== null) {
                $this->revokeFamily($reuseFamilyId, RefreshToken::REASON_REUSE_DETECTED);
            }
            throw $e;
        }
    }

    public function revoke(string $plain, string $reason = RefreshToken::REASON_LOGOUT): bool
    {
        $token = RefreshToken::where('token_hash', $this->hash($plain))->first();
        if ($token === null) {
            return false;
        }

        $token->revoked_at = now();
        $token->revoked_reason = $reason;
        $token->save();

        return true;
    }

    public function revokeFamily(string $familyId, string $reason = RefreshToken::REASON_ADMIN): int
    {
        return RefreshToken::where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
            ]);
    }

    private function generatePlainToken(): string
    {
        // 64 chars, URL-safe random — 384 bits of entropy
        return Str::random(64);
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
