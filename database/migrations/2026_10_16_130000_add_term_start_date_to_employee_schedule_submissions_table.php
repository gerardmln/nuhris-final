<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_schedule_submissions', function (Blueprint $table): void {
            // First day of the term, entered by Contractual Full-Time Faculty.
            // Approving the schedule starts their leave term on this date.
            $table->date('term_start_date')->nullable()->after('academic_year');
        });
    }

    public function down(): void
    {
        Schema::table('employee_schedule_submissions', function (Blueprint $table): void {
            $table->dropColumn('term_start_date');
        });
    }
};
