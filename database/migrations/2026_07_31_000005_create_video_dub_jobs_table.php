<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_dub_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Pipeline config
            $table->string('target_language');
            $table->string('voice_id');
            $table->string('provider')->default('elevenlabs');
            $table->string('model_id')->nullable();
            $table->json('voice_settings')->nullable();

            // Results
            $table->string('source_language')->nullable();
            $table->longText('srt_original')->nullable();
            $table->longText('srt_translated')->nullable();

            // Status tracking
            $table->string('status')->default('queued'); // queued, processing, tts_pending, completed, failed
            $table->string('stage')->default('queued');  // queued, transcribing, translating, tts, done
            $table->text('error')->nullable();

            // Credits
            $table->integer('characters_used')->default(0);
            $table->integer('credits_deducted')->default(0);

            // TTS results
            // text, not string: signed CDN/S3 URLs from this provider routinely
            // exceed 255 chars — the same reason tts_histories.audio_url was
            // widened in 2026_07_31_000002_widen_tts_histories_text_and_audio_url_columns.
            $table->text('audio_url')->nullable();
            $table->json('audio_urls')->nullable();
            $table->integer('duration_seconds')->nullable();

            // Link to TtsHistory rows
            $table->json('tts_task_ids')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_dub_jobs');
    }
};
