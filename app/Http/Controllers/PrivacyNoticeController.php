<?php

namespace App\Http\Controllers;

use App\Models\PrivacyNoticeAcknowledgment;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PrivacyNoticeController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $version = (string) config('privacy.notice_version', '1.0');

        if ($request->session()->get('privacy_notice_acknowledged_version') === $version) {
            return redirect()->intended($this->dashboardRoute($request));
        }

        return view('privacy.notice', [
            'version' => $version,
        ]);
    }

    public function acknowledge(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'acknowledged' => ['accepted'],
        ]);
        $version = (string) config('privacy.notice_version', '1.0');
        $user = $request->user();

        DB::transaction(function () use ($user, $version): void {
            PrivacyNoticeAcknowledgment::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'privacy_notice_version' => $version,
                ],
                [
                    'acknowledged_at' => now(),
                ],
            );
        });

        $request->session()->put('privacy_notice_acknowledged_version', $version);

        app(AuditLogService::class)->record(
            'PRIVACY_NOTICE_ACKNOWLEDGED',
            'Privacy Notice',
            'Acknowledged Privacy Notice version '.$version.'.',
            'Success',
            ['privacy_notice_version' => $version],
            $user->id,
        );

        return redirect()->intended($this->dashboardRoute($request));
    }

    private function dashboardRoute(Request $request): string
    {
        return match ((int) $request->user()->user_type) {
            1 => route('admin.dashboard', absolute: false),
            2 => route('dashboard', absolute: false),
            default => route('employee.dashboard', absolute: false),
        };
    }
}
