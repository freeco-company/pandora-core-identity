<?php

namespace App\Http\Controllers\Auth;

use App\Models\GroupUserIdentity;
use App\Services\Auth\JwtIssuer;
use App\Services\Auth\OAuthLoginResult;
use App\Services\Auth\OAuthLoginService;
use App\Services\Auth\RefreshTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

/**
 * OAuth provider redirect / callback handler.
 *
 * Provider mapping:
 *   google → GroupUserIdentity::TYPE_GOOGLE
 *   line   → TYPE_LINE
 *   apple  → TYPE_APPLE
 *
 * Callback flow:
 *   1. Socialite 拿 provider profile（id / email / name）
 *   2. OAuthLoginService 決定 existing / created / merge_suggested
 *   3. existing|created → 簽 access + refresh，導回 client
 *   4. merge_suggested → 回 JSON（含 merge_token），client 走 confirm flow
 */
class OAuthController
{
    private const ALLOWED_PROVIDERS = [
        'google' => GroupUserIdentity::TYPE_GOOGLE,
        'line' => GroupUserIdentity::TYPE_LINE,
        'apple' => GroupUserIdentity::TYPE_APPLE,
    ];

    public function __construct(
        private readonly OAuthLoginService $loginService,
        private readonly JwtIssuer $issuer,
        private readonly RefreshTokenService $refreshService,
    ) {}

    public function redirect(string $provider): RedirectResponse|JsonResponse
    {
        if (! array_key_exists($provider, self::ALLOWED_PROVIDERS)) {
            return response()->json(['error' => 'unknown_provider'], 404);
        }

        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function callback(Request $request, string $provider): JsonResponse
    {
        if (! array_key_exists($provider, self::ALLOWED_PROVIDERS)) {
            return response()->json(['error' => 'unknown_provider'], 404);
        }
        $providerType = self::ALLOWED_PROVIDERS[$provider];
        $productCode = (string) $request->query('product_code', 'fp');

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'oauth_callback_failed',
                'detail' => $e->getMessage(),
            ], 400);
        }

        $result = $this->loginService->loginOrCreate(
            providerType: $providerType,
            providerUserId: (string) $socialUser->getId(),
            email: $socialUser->getEmail(),
            displayName: $socialUser->getName(),
            rawPayload: [
                'token' => $socialUser->token ?? null,
                'refresh_token' => $socialUser->refreshToken ?? null,
                'expires_in' => $socialUser->expiresIn ?? null,
                'avatar' => $socialUser->getAvatar(),
            ],
        );

        return $this->respondWithResult($result, $productCode, $request);
    }

    private function respondWithResult(OAuthLoginResult $result, string $productCode, Request $request): JsonResponse
    {
        if ($result->status === OAuthLoginResult::MERGE_SUGGESTED) {
            return response()->json([
                'status' => 'merge_suggested',
                'existing_user' => [
                    'id' => $result->user->id,
                    'email_canonical' => $result->user->email_canonical,
                    'display_name' => $result->user->display_name,
                ],
                'pending_identity' => $result->pendingIdentity,
                'next_step' => 'POST /v1/auth/oauth/confirm-merge with verification of existing account',
            ], 409);
        }

        $access = $this->issuer->issueAccessToken($result->user, $productCode, ['profile:read', 'profile:write']);
        $refresh = $this->refreshService->issue(
            $result->user,
            $productCode,
            null,
            $request->ip(),
            substr((string) $request->userAgent(), 0, 1024),
        );

        return response()->json([
            'status' => $result->status,
            'access_token' => $access,
            'refresh_token' => $refresh->plain,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('pandora_jwt.access_ttl'),
            'user' => [
                'id' => $result->user->id,
                'email_canonical' => $result->user->email_canonical,
                'display_name' => $result->user->display_name,
            ],
        ]);
    }
}
