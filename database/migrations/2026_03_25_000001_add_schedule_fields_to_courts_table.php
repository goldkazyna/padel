<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            $table->time('open_time')->default('08:00:00')->after('sort_order');
            $table->time('close_time')->default('22:00:00')->after('open_time');
            $table->unsignedInteger('slot_duration')->default(60)->after('close_time');
        });
    }

    public function down(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            $table->dropColumn(['open_time', 'close_time', 'slot_duration']);
        });
    }
};
