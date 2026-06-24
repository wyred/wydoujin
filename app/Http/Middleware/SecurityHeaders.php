<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Adds defense-in-depth response headers (clickjacking, sniffing, referrer, CSP).
 * 防御的なレスポンスヘッダを付与（クリックジャッキング/スニッフ/CSP）。
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // CSP is skipped in local dev so Vite's HMR (separate-origin scripts + websocket)
        // keeps working. Alpine evaluates its expressions via eval/new Function, so script-src
        // needs 'unsafe-eval' + 'unsafe-inline' (its XSS value is thus limited) — but the CSP
        // still blocks EXTERNAL script/resource loads and framing (clickjacking), which is the
        // point here. / Alpineはevalを使うため'unsafe-eval'必須。外部読み込みとframingの遮断が主目的。
        if (! app()->environment('local')) {
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "img-src 'self' data:",
                "style-src 'self' 'unsafe-inline'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
                "object-src 'none'",
                "base-uri 'self'",
                "frame-ancestors 'none'",
            ]));
        }

        return $response;
    }
}
