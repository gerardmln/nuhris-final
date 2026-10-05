<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_credentials', 'degree_level')) {
            Schema::table('employee_credentials', function (Blueprint $table): void {
                $table->string('degree_level')->nullable()->after('credential_type');
                $table->index(['employee_id', 'credential_type', 'degree_level']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employee_credentials', 'degree_level')) {
            Schema::table('employee_credentials', function (Blueprint $table): void {
                $table->dropIndex(['employee_id', 'credential_type', 'degree_level']);
                $table->dropColumn('degree_level');
            });
        }
    }
};
