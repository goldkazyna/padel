<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_participants', function (Blueprint $t) {
            $t->timestamp('reminded_1h_at')->nullable()->after('reminded_2h_at');
        });
    }

    public function down(): void
    {
        Schema::table('tournament_participants', function (Blueprint $t) {
            $t->dropColumn('reminded_1h_at');
        });
    }
};
