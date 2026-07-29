<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // Snapshot of the tokenable's token_version at the moment the token
            // was minted. CheckTokenVersion middleware compares this against the
            // user's *current* token_version to detect revoked/stale tokens
            // (e.g. after a password reset bumps the user's version). Without
            // this column, there is nothing to compare against: the guard
            // resolves $request->user() as the exact same object instance as
            // $token->tokenable, so a naive comparison of the two is a tautology
            // that can never be false.
            $table->unsignedInteger('token_version')->default(1)->after('abilities');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('token_version');
        });
    }
};
