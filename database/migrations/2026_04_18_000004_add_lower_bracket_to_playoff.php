<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->boolean('has_lower_bracket')->default(false)->after('playoff_format');
            $table->boolean('has_bronze_match')->default(false)->after('has_lower_bracket');
        });

        Schema::table('tournament_playoff_matches', function (Blueprint $table) {
            $table->string('bracket', 10)->default('upper')->after('stage');
            $table->boolean('is_bronze')->default(false)->after('bracket');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['has_lower_bracket', 'has_bronze_match']);
        });
        Schema::table('tournament_playoff_matches', function (Blueprint $table) {
            $table->dropColumn(['bracket', 'is_bronze']);
        });
    }
};
