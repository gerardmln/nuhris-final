<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        $request->session()->forget('privacy_notice_acknowledged_version');

        app(AuditLogService::class)->record(
            'LOGIN',
            'Authentication',
            'Logged in as '.$request->user()->email.'.',
            'Success',
            ['email' => $request->user()->email]
        );

        return redirect()->route('privacy.notice');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user) {
            app(AuditLogService::class)->record(
                'LOGOUT',
                'Authentication',
                'Logged out '.$user->email.'.',
                'Success',
                ['email' => $user->email],
                $user->id
            );
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
