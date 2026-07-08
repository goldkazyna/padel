<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Клуб-площадка турнира: необязательное поле «где играем».
 * Отдельно от club_id (организатор). Nullable, при удалении клуба — обнуляется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->foreignId('venue_club_id')
                ->nullable()
                ->after('club_id')
                ->constrained('clubs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('venue_club_id');
        });
    }
};
