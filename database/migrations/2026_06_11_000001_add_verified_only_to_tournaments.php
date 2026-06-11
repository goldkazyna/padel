<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // Турнир только для верифицированных игроков (level_verified).
            $table->boolean('verified_only')->default(false)->after('is_rated');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('verified_only');
        });
    }
};
