<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE academic_calendar_entries MODIFY entry_type ENUM('holiday', 'event', 'wfh_class_suspension') NOT NULL");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::table('academic_calendar_entries')
            ->where('entry_type', 'wfh_class_suspension')
            ->update(['entry_type' => 'event']);

        DB::statement("ALTER TABLE academic_calendar_entries MODIFY entry_type ENUM('holiday', 'event') NOT NULL");
    }
};