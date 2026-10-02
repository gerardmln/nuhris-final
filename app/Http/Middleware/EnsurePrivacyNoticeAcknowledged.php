<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivacyNoticeAcknowledged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $version = (string) config('privacy.notice_version', '1.0');

        if (! $user || $request->session()->get('privacy_notice_acknowledged_version') === $version) {
            return $next($request);
        }

        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('privacy.notice');
    }
}
