<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // 'firmware'/'flash_tool' exist in the source system (ESP32-specific,
            // out of scope) — this project only ever populates 'cmb_core', but the
            // column stays free-text for future extensibility rather than an enum.
            $table->string('type');
            $table->string('version');
            $table->text('description')->nullable();
            $table->text('download_url');
            // Free-text, not a strict byte count — the real source data mixes raw
            // byte strings ("2784") and pre-formatted strings ("202 MB").
            $table->string('file_size')->nullable();
            $table->string('sha256')->nullable();
            $table->longText('changelog')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_latest')->default(false);
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index(['type', 'is_latest']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tools');
    }
};
