<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_histories', function (Blueprint $table) {
            // MySQL TEXT caps at 65,535 bytes; an SRT file up to the route's
            // 512KB validation limit can exceed that. MEDIUMTEXT caps at 16MB.
            $table->mediumText('text')->change();

            // Default VARCHAR(255) truncates signed CDN/S3 URLs, which routinely
            // exceed 255 characters — a truncated URL means a user who paid for
            // TTS can't retrieve their audio.
            $table->text('audio_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tts_histories', function (Blueprint $table) {
            $table->text('text')->change();
            $table->string('audio_url')->nullable()->change();
        });
    }
};
