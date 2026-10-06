<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_nonce', $nonce);

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval' https://cdn.tailwindcss.com",
            "script-src-attr 'unsafe-inline'",
            "style-src 'self' 'nonce-{$nonce}' https://fonts.bunny.net",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' https://fonts.bunny.net data:",
            "connect-src 'self' https://*.supabase.co https://fonts.bunny.net",
            "frame-src 'self' https://*.supabase.co https://view.officeapps.live.com",
        ]));
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->isSecure() || app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            $response->headers->set(
                'Content-Security-Policy',
                $response->headers->get('Content-Security-Policy').'; upgrade-insecure-requests'
            );
        }

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        if ($response->isRedirection()) {
            $response->setContent('');
        }

        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}