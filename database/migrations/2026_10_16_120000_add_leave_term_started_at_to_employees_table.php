<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            // Start of the current leave term for employees whose leave resets
            // per term (Contractual Full-Time Faculty); set when schedules reset.
            $table->date('leave_term_started_at')->nullable()->after('hire_date');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('leave_term_started_at');
        });
    }
};
