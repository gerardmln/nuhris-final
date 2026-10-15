<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extraction_validation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pdf_filename');
            $table->string('ground_truth_filename');
            $table->string('format');
            $table->unsignedInteger('pages')->default(0);
            $table->decimal('extraction_seconds', 8, 2)->default(0);
            $table->unsignedInteger('expected_rows');
            $table->unsignedInteger('extracted_rows');
            $table->unsignedInteger('matched_rows');
            $table->unsignedInteger('time_in_correct');
            $table->unsignedInteger('time_out_correct');
            $table->unsignedInteger('fully_correct_rows');
            $table->unsignedInteger('employee_matched_rows');
            $table->json('mismatches')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extraction_validation_runs');
    }
};
