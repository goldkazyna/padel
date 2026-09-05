<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Тумблеры уведомлений амигос.
 *
 * Два, а не один: пуши про активность («вас добавили», «ищет игрока») и пуши
 * про личные сообщения раздражают по-разному, и выключать их хотят порознь.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_amigos')->default(true);
            $table->boolean('notify_messages')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notify_amigos', 'notify_messages']);
        });
    }
};
