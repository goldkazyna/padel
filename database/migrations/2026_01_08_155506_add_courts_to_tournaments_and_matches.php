<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Названия кортов в турнире
        Schema::table('tournaments', function (Blueprint $table) {
            $table->json('courts')->nullable()->after('reserve_count');
        });
        
        // Номер корта в матче Американо
        Schema::table('americano_matches', function (Blueprint $table) {
            $table->integer('court_number')->nullable()->after('americano_round_id');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('courts');
        });
        
        Schema::table('americano_matches', function (Blueprint $table) {
            $table->dropColumn('court_number');
        });
    }
};