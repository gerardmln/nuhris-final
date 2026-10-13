<?php

namespace App\Jobs;

use App\Http\Controllers\Hr\OperationsController;
use App\Models\User;
use App\Services\LeaveMonitoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessLeaveUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public string $path,
        public ?int $actorId = null,
    ) {
    }

    public function handle(OperationsController $controller, LeaveMonitoringService $leaveMonitoringService): void
    {
        $user = $this->actorId ? User::find($this->actorId) : null;
        $request = Request::create('/hr/leave-management/upload', 'POST');
        $request->setUserResolver(fn () => $user);
        $request->files->set('leaves_file', new UploadedFile(
            Storage::disk('local')->path($this->path),
            basename($this->path),
            null,
            null,
            true,
        ));

        try {
            $controller->processLeaveUpload($request, $leaveMonitoringService);
        } finally {
            Storage::disk('local')->delete($this->path);
        }
    }
}