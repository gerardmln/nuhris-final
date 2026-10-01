<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_notice_acknowledgments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('privacy_notice_version', 20);
            $table->timestamp('acknowledged_at');
            $table->timestamps();

            $table->unique(['user_id', 'privacy_notice_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_notice_acknowledgments');
    }
};
