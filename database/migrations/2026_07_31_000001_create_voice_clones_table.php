<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_clones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('provider_voice_id');
            $table->string('voice_name')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->unique('provider_voice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_clones');
    }
};
