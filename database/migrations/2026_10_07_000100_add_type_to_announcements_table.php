<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('type')->default('notification')->after('content');
            $table->index('type');
        });

        DB::table('announcements')
            ->where(function ($query) {
                $query->whereNull('target_employee_type')
                    ->orWhereIn('target_employee_type', ['faculty', 'admin_support']);
            })
            ->whereIn('created_by', User::query()->where('user_type', User::TYPE_HR)->pluck('id'))
            ->update(['type' => 'announcement']);
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
