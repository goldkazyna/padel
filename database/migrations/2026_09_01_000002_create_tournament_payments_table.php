<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Оплата участия в турнире через Plexy.
 *
 * Пока платёж не прошёл, участника в турнире нет — но место за ним
 * держится до expires_at, иначе человек оплатит уже занятое место.
 * После оплаты запись создаётся сразу в основном списке, без модерации:
 * деньги и есть подтверждение.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Записал с собой друга — платит за двоих одной ссылкой.
            $table->foreignId('friend_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('players_count')->default(1);
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->default('pending');
            $table->string('plexy_link_id')->nullable();
            $table->text('plexy_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['tournament_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('plexy_link_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_payments');
    }
};
