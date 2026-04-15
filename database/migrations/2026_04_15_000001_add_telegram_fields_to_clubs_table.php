<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('telegram_channel_id')->nullable()->after('features');
            $table->string('telegram_bot_token')->nullable()->after('telegram_channel_id');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn(['telegram_channel_id', 'telegram_bot_token']);
        });
    }
};
