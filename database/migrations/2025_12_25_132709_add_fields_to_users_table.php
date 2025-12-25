<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->after('id');
            $table->string('last_name')->after('first_name');
            $table->string('phone')->nullable()->after('email');
            $table->string('avatar')->nullable()->after('password');
            $table->date('birth_date')->nullable()->after('avatar');
            $table->enum('gender', ['male', 'female'])->nullable()->after('birth_date');
            $table->enum('role', ['player', 'club_admin', 'super_admin'])->default('player')->after('gender');
            $table->integer('rating')->default(1000)->after('role');
            $table->decimal('level', 3, 2)->default(1.00)->after('rating');
            $table->string('telegram_id')->nullable()->after('level');
            $table->timestamp('last_played_at')->nullable()->after('telegram_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'first_name', 'last_name', 'phone', 'avatar', 'birth_date',
                'gender', 'role', 'rating', 'level', 'telegram_id', 'last_played_at'
            ]);
        });
    }
};