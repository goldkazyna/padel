<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_group_attendance', function (Blueprint $table) {
            $table->boolean('is_trial')->default(false)->after('charged');
            $table->integer('trial_amount')->nullable()->after('is_trial');
            // Пробный гость не является членом группы — привязка к клиенту напрямую.
            $table->foreignId('client_id')->nullable()->after('group_member_id')
                ->constrained('club_clients')->nullOnDelete();
            // У гостевой строки нет членства — делаем nullable.
            $table->foreignId('group_member_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('club_group_attendance', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['is_trial', 'trial_amount']);
        });
    }
};
