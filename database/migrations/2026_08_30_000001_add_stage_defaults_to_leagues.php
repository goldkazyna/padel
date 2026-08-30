<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Настройки этапов на уровне лиги.
 *
 * Все восемь этапов играются одинаково, поэтому формат задаётся один раз при
 * создании лиги, а в форме этапа остаётся только то, что меняется от вечера
 * к вечеру: дата, места и корты.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('leagues', 'is_paired')) {
            return;
        }

        Schema::table('leagues', function (Blueprint $table) {
            // Парный Flex: фиксированные пары, партнёр не меняется.
            $table->boolean('is_paired')->default(false)->after('format');
            $table->unsignedTinyInteger('courts_count')->default(2)->after('is_paired');
            $table->unsignedTinyInteger('duration_hours')->nullable()->after('courts_count');
            $table->unsignedSmallInteger('points_to_win')->nullable()->after('duration_hours');
            $table->boolean('verified_only')->default(false)->after('points_to_win');
            $table->boolean('chat_enabled')->default(true)->after('verified_only');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('leagues', 'is_paired')) {
            return;
        }

        Schema::table('leagues', function (Blueprint $table) {
            $table->dropColumn([
                'is_paired', 'courts_count', 'duration_hours',
                'points_to_win', 'verified_only', 'chat_enabled',
            ]);
        });
    }
};
