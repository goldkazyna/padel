<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_coaches', function (Blueprint $table) {
            $table->text('info')->nullable()->after('photo');
            $table->json('certificates')->nullable()->after('info');
            $table->string('rating')->nullable()->after('certificates');
        });
    }

    public function down(): void
    {
        Schema::table('club_coaches', function (Blueprint $table) {
            $table->dropColumn(['info', 'certificates', 'rating']);
        });
    }
};
