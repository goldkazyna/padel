<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_clients', function (Blueprint $table) {
            if (!Schema::hasColumn('club_clients', 'email')) {
                $table->string('email')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('club_clients', 'card_number')) {
                $table->string('card_number', 50)->nullable()->after('email');
                $table->index(['club_id', 'card_number']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('club_clients', function (Blueprint $table) {
            if (Schema::hasColumn('club_clients', 'card_number')) {
                $table->dropIndex(['club_id', 'card_number']);
                $table->dropColumn('card_number');
            }
            if (Schema::hasColumn('club_clients', 'email')) {
                $table->dropColumn('email');
            }
        });
    }
};
