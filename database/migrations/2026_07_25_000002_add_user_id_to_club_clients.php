<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Явная связь клиента клуба с пользователем приложения.
 * Нужна, когда телефон клиента в базе клуба ≠ телефону в приложении —
 * клуб привязывает вручную в CRM. Приложение показывает карты по
 * user_id ИЛИ по совпадению телефона.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('club_clients', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('club_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('club_clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
