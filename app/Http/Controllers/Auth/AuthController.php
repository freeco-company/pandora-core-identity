<?php

namespace App\Http\Controllers\Auth;

use App\Models\GroupUser;
use App\Services\Auth\JwtIssuer;
use App\Services\Auth\RefreshTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 認證相關 endpoints。
 *
 * 目前實作（#3）：
 *   - POST /v1/auth/refresh    用 refresh token 換新 access + refresh
 *   - POST /v1/auth/logout     撤銷 refresh token
 *   - GET  /v1/auth/public-key 給各 App 抓 public key 用
 *
 * 留待 #4 實作：
 *   - GET /v1/auth/oauth/{provider}/redirect|callback
 *   - POST /v1/auth/email/login|register
 */
class AuthController
{
    public function __construct(
        private readonly JwtIssuer $issuer,
        private readonly RefreshTokenService $refreshService,
    ) {}

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string'],
            'product_code' => ['required', 'string'],
        ]);

        try {
            $issued = $this->refreshService->rotate(
                $data['refresh_token'],
                $data['product_code'],
                $request->ip(),
                substr((string) $request->userAgent(), 0, 1024),
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => 'refresh_failed',
                'detail' => $e->getMessage(),
            ], 401);
        }

        $user = GroupUser::find($issued->record->group_user_id);
        if ($user === null) {
            return response()->json(['error' => 'user_not_found'], 401);
        }

        $access = $this->issuer->issueAccessToken($user, $data['product_code'], ['profile:read', 'profile:write']);

        return response()->json([
            'access_token' => $access,
            'refresh_token' => $issued->plain,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('pandora_jwt.access_ttl'),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $revoked = $this->refreshService->revoke($data['refresh_token']);

        return response()->json([
            'revoked' => $revoked,
        ]);
    }

    public function publicKey(): JsonResponse
    {
        return response()->json([
            'algorithm' => 'RS256',
            'public_key' => $this->issuer->getPublicKeyPem(),
            'issuer' => config('pandora_jwt.issuer'),
        ]);
    }

    public function scopeTest(Request $request): JsonResponse
    {
        /** @var GroupUser $user */
        $user = $request->attributes->get('group_user');

        return response()->json([
            'group_user_id' => $user->id,
            'status' => $user->status,
        ]);
    }
}
