<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE academic_calendar_entries DROP CONSTRAINT IF EXISTS academic_calendar_entries_entry_type_check');
        DB::statement("ALTER TABLE academic_calendar_entries ADD CONSTRAINT academic_calendar_entries_entry_type_check CHECK (entry_type IN ('holiday', 'event', 'wfh_class_suspension'))");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('academic_calendar_entries')
            ->where('entry_type', 'wfh_class_suspension')
            ->update(['entry_type' => 'event']);

        DB::statement('ALTER TABLE academic_calendar_entries DROP CONSTRAINT IF EXISTS academic_calendar_entries_entry_type_check');
        DB::statement("ALTER TABLE academic_calendar_entries ADD CONSTRAINT academic_calendar_entries_entry_type_check CHECK (entry_type IN ('holiday', 'event'))");
    }
};
