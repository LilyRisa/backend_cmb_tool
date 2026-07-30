<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tts_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('genmax_task_id')->nullable()->index();
            $table->string('provider')->default('elevenlabs');
            $table->string('voice_id');
            $table->string('model_id')->nullable();
            $table->text('text');
            $table->string('language_code')->nullable();
            $table->json('voice_settings')->nullable();
            $table->string('status')->default('pending');
            $table->integer('progress')->default(0);
            $table->integer('characters_used')->default(0);
            $table->integer('credits_deducted_provider')->default(0);
            $table->integer('credits_deducted_user')->default(0);
            $table->string('audio_url')->nullable();
            $table->text('error')->nullable();
            $table->boolean('is_credit_deducted')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tts_histories');
    }
};
