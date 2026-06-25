<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token gate for the /api/v1 machine API. Fail-closed: an unset/empty
 * server token disables the whole API (503), so a misconfigured deploy never
 * exposes write endpoints unauthenticated. / APIトークン認証（未設定なら無効化）。
 */
class EnsureApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('app.api_token');

        // Unset/empty → API disabled (opposite of the web APP_PASSWORD rule, on purpose). / 未設定は無効。
        if ($token === null || $token === '') {
            return response()->json(['message' => 'API disabled'], 503);
        }

        // Accept a standard bearer token or the X-Api-Token convenience header. / Bearer か X-Api-Token。
        $presented = $request->bearerToken() ?: $request->header('X-Api-Token', '');

        if (! is_string($presented) || $presented === '' || ! hash_equals((string) $token, $presented)) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        return $next($request);
    }
}
