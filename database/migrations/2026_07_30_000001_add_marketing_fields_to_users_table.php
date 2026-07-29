<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('token_version')->default(1);
            $table->string('avatar')->nullable();
            $table->boolean('is_admin')->default(false);

            $table->integer('credits')->default(0);
            $table->integer('monthly_credits')->default(0);
            $table->integer('purchased_credits')->default(0);
            $table->timestamp('credits_reset_at')->nullable();

            $table->string('package_type')->default('free');
            $table->timestamp('package_expires_at')->nullable();

            $table->string('referral_code', 8)->unique()->nullable();
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by');
            $table->dropColumn([
                'token_version', 'avatar', 'is_admin',
                'credits', 'monthly_credits', 'purchased_credits', 'credits_reset_at',
                'package_type', 'package_expires_at', 'referral_code',
            ]);
        });
    }
};
