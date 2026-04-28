<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dev/staging only：用 shared secret header 守護 internal endpoints。
 *
 * Production（ADR-006）改 mTLS，本 middleware 仍可作為第二道（defense in depth）。
 *
 * Header: X-Pandora-Internal-Secret
 * Env:    PANDORA_INTERNAL_SECRET
 */
class InternalSharedSecretMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('pandora_jwt.internal_secret', '');
        if ($expected === '') {
            return response()->json(['error' => 'internal_secret_not_configured'], 500);
        }

        $given = (string) $request->header('X-Pandora-Internal-Secret', '');
        if (! hash_equals($expected, $given)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
