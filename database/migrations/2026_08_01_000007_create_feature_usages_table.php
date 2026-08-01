<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_usages', function (Blueprint $table) {
            $table->id();
            // nullOnDelete: pure analytics data, worth keeping in aggregate even
            // if the specific user account is later deleted.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('feature_name');
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'feature_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_usages');
    }
};
