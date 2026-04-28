<?php

namespace App\Http\Middleware;

use App\Models\GroupUser;
use App\Services\Auth\JwtIssuer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JWT 驗證 middleware。
 *
 * 用法：
 *   Route::get('/users/me', [...])->middleware(['pandora.jwt:fp']);
 *   Route::get('/admin/...', [...])->middleware(['pandora.jwt:fp,admin:full']);
 *
 * 第一個參數 = product code，後續為必要 scopes。
 *
 * 為了讓 #4 OAuth controller 可以拿到「當前 user」，這裡會 attach
 * GroupUser instance 到 request：$request->groupUser()。
 */
class JwtAuthMiddleware
{
    public function __construct(private readonly JwtIssuer $issuer) {}

    public function handle(Request $request, Closure $next, string $productCode, string ...$requiredScopes): Response
    {
        $bearer = $this->extractBearer($request);
        if ($bearer === null) {
            return response()->json(['error' => 'missing_token'], 401);
        }

        try {
            $token = $this->issuer->verify($bearer, $productCode);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'invalid_token', 'detail' => $e->getMessage()], 401);
        }

        $claims = $token->claims();
        $tokenScopes = (array) $claims->get('scopes', []);
        foreach ($requiredScopes as $scope) {
            if (! in_array($scope, $tokenScopes, true)) {
                return response()->json(['error' => 'insufficient_scope', 'required' => $scope], 403);
            }
        }

        $sub = (string) $claims->get('sub');
        $user = GroupUser::find($sub);
        if ($user === null) {
            return response()->json(['error' => 'user_not_found'], 401);
        }
        if ($user->status !== 'active') {
            return response()->json(['error' => 'user_not_active', 'status' => $user->status], 403);
        }

        $request->attributes->set('group_user', $user);
        $request->attributes->set('jwt_claims', $claims);

        return $next($request);
    }

    private function extractBearer(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }
        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }
}
