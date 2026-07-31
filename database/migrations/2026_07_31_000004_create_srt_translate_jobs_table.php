<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('srt_translate_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->string('target_language');

            $table->string('source_language')->nullable();
            $table->longText('srt_original')->nullable();
            $table->longText('srt_translated')->nullable();

            $table->string('status')->default('queued');
            $table->string('stage')->default('queued');
            $table->text('error')->nullable();

            $table->integer('characters_used')->default(0);
            $table->integer('credits_deducted')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('srt_translate_jobs');
    }
};
