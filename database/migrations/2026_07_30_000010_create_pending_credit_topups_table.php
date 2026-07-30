<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_credit_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('package_id', 32);
            $table->unsignedInteger('credits');
            $table->unsignedInteger('amount');
            $table->string('transaction_code', 64)->unique();
            $table->enum('status', ['pending', 'completed', 'expired'])->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'transaction_code']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_credit_topups');
    }
};
