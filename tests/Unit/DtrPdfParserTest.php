<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\OperationsController as AdminOperationsController;
use App\Http\Controllers\Hr\OperationsController as HrOperationsController;
use ReflectionClass;
use Tests\TestCase;

class DtrPdfParserTest extends TestCase
{
    public function test_admin_parser_reads_civil_service_form_48_rows(): void
    {
        $records = $this->parseWith(AdminOperationsController::class, <<<'TEXT'
Abante, Marciano C
For the month of
September 2026
7:36 am 5:30 pm 1 7:36 am 5:30 pm1
5:26 pm 4 5:26 pm4 8 8
8:06 am 4:49 pm 6:47 pm 17 8:06 am 4:49 pm17 6:47 pm 3911
TEXT
        );

        $this->assertSame([
            [
                'employee_id' => '',
                'name' => 'Abante, Marciano C',
                'date' => '2026-09-01',
                'time_in' => '07:36',
                'time_out' => '17:30',
            ],
            [
                'employee_id' => '',
                'name' => 'Abante, Marciano C',
                'date' => '2026-09-04',
                'time_in' => '17:26',
                'time_out' => null,
            ],
            [
                'employee_id' => '',
                'name' => 'Abante, Marciano C',
                'date' => '2026-09-17',
                'time_in' => '08:06',
                'time_out' => '18:47',
            ],
        ], $records);
    }

    public function test_hr_parser_reads_same_civil_service_format(): void
    {
        $records = $this->parseWith(HrOperationsController::class, <<<'TEXT'
Santos, Ana Maria
For the month of
September 2026
8:00 am 5:00 pm 1 8:00 am 5:00 pm1
8:15 am 12:00 pm 2 8:15 am 12:00 pm2
TEXT
        );

        $this->assertCount(2, $records);
        $this->assertSame('2026-09-02', $records[1]['date']);
        $this->assertSame('08:15', $records[1]['time_in']);
        $this->assertSame('12:00', $records[1]['time_out']);
    }

    private function parseWith(string $controllerClass, string $text): array
    {
        $controller = (new ReflectionClass($controllerClass))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass($controllerClass))->getMethod('parseBiometricText');

        return $method->invoke($controller, $text);
    }
}
