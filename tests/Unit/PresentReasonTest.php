<?php

namespace Tests\Unit;

use App\Services\EmployeeScheduleService;
use Tests\TestCase;

class PresentReasonTest extends TestCase
{
    public function test_present_reason_is_derived_from_attendance_notes(): void
    {
        $service = app(EmployeeScheduleService::class);

        $this->assertSame('Sick Leave', $service->presentReason('Approved leave: Sick Leave'));
        $this->assertSame('Vacation Leave', $service->presentReason(null, 'Auto-fulfilled from approved leave: Vacation Leave'));
        $this->assertSame('Holiday', $service->presentReason('Academic calendar non-working date (holiday): auto-marked present due to scheduled shift'));
        $this->assertSame('WFH', $service->presentReason(null, 'WFH approved by HR'));
        $this->assertSame('WFH', $service->presentReason('WFH approved via monitoring sheet'));
        $this->assertSame('Sick Leave', $service->presentReason('Approved leave: Sick Leave', 'WFH approved by HR'));
    }

    public function test_no_reason_for_regular_or_non_present_notes(): void
    {
        $service = app(EmployeeScheduleService::class);

        $this->assertNull($service->presentReason(null, null));
        $this->assertNull($service->presentReason('Academic calendar working date'));
        $this->assertNull($service->presentReason('Approved leave (non-regular): Sick Leave'));
        $this->assertNull($service->presentReason('Non-working day: present due to attendance record'));
    }
}
