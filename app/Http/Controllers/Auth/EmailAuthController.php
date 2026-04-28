<?php

namespace App\Http\Controllers\Auth;

use App\Services\Auth\EmailAuthService;
use App\Services\Auth\JwtIssuer;
use App\Services\Auth\RefreshTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailAuthController
{
    public function __construct(
        private readonly EmailAuthService $emailService,
        private readonly JwtIssuer $issuer,
        private readonly RefreshTokenService $refreshService,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $user = $this->emailService->register($data['email'], $data['password'], $data['display_name'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'register_failed', 'detail' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'pending_verification',
            'user_id' => $user->id,
            // Dev only — production 改寄信
            'verification_token_hint' => app()->environment('local', 'testing') ? $user->email_verification_token : null,
            'message' => 'Verification email queued. Click the link in your inbox to complete signup.',
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'product_code' => ['required', 'string'],
        ]);

        try {
            $user = $this->emailService->login($data['email'], $data['password']);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'login_failed', 'detail' => $e->getMessage()], 401);
        }

        $access = $this->issuer->issueAccessToken($user, $data['product_code'], ['profile:read', 'profile:write']);
        $refresh = $this->refreshService->issue(
            $user,
            $data['product_code'],
            null,
            $request->ip(),
            substr((string) $request->userAgent(), 0, 1024),
        );

        return response()->json([
            'access_token' => $access,
            'refresh_token' => $refresh->plain,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('pandora_jwt.access_ttl'),
            'user' => [
                'id' => $user->id,
                'email_canonical' => $user->email_canonical,
                'display_name' => $user->display_name,
            ],
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        try {
            $user = $this->emailService->verifyEmail($data['token']);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'verify_failed', 'detail' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'verified',
            'user_id' => $user->id,
        ]);
    }
}
