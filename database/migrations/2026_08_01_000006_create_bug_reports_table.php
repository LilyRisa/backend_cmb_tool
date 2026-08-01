<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bug_reports', function (Blueprint $table) {
            $table->id();
            // nullOnDelete (not cascade): a bug report is a support-ticket-style
            // record worth keeping for history even if the reporting user's
            // account is later deleted — same reasoning as feature_usages.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description');
            $table->json('screenshots')->nullable();
            $table->unsignedInteger('screenshot_count')->default(0);
            $table->string('app_version')->nullable();
            $table->text('device_info')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('status')->default('pending'); // pending, in_progress, resolved, wont_fix
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bug_reports');
    }
};
