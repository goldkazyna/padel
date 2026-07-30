<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->timestamp('reminded_1d_at')->nullable()->after('score_locked');
            $table->timestamp('reminded_2h_at')->nullable()->after('reminded_1d_at');
            $table->timestamp('reminded_1h_at')->nullable()->after('reminded_2h_at');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['reminded_1d_at', 'reminded_2h_at', 'reminded_1h_at']);
        });
    }
};
