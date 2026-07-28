<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram-уведомления о бронях клуба. Токен бота переиспользуем существующий
 * (clubs.telegram_bot_token). Добавляем список chat id получателей + тумблер.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->boolean('telegram_notify_enabled')->default(false);
            $table->text('telegram_chat_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn(['telegram_notify_enabled', 'telegram_chat_ids']);
        });
    }
};
