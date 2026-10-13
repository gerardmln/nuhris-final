<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->string('source_leave_id')->nullable()->after('employee_id');
            $table->unique(['employee_id', 'source_leave_id']);
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropUnique(['employee_id', 'source_leave_id']);
            $table->dropColumn('source_leave_id');
        });
    }
};
