<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Дизайн клубной карты в приложении: цвета фона / акцента / прогресс-бара.
 * Хранятся как HEX-строки (#RRGGBB); null — берутся дефолты в приложении.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('card_bg_color', 9)->nullable()->after('booking_cancel_hours');
            $table->string('card_accent_color', 9)->nullable()->after('card_bg_color');
            $table->string('card_progress_color', 9)->nullable()->after('card_accent_color');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn(['card_bg_color', 'card_accent_color', 'card_progress_color']);
        });
    }
};
