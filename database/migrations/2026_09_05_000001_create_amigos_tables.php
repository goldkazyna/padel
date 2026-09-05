<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Амигос: связи между игроками, личная переписка, блокировки и жалобы.
 *
 * Связь односторонняя — добавление не требует согласия. Взаимность видна тем,
 * что существуют обе строки, отдельного поля под неё нет: иначе его пришлось
 * бы держать в согласованном состоянии при каждом удалении.
 *
 * Переписка один-на-один: пара пользователей хранится упорядоченной
 * (user_one_id < user_two_id), поэтому диалог между двумя людьми всегда один,
 * с какой бы стороны его ни открыли.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('following_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['follower_id', 'following_id']);
            // Обратная выборка «кто добавил меня» и подсчёт взаимности.
            $table->index('following_id');
        });

        Schema::create('user_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'blocked_user_id']);
            $table->index('blocked_user_id');
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_one_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_two_id')->constrained('users')->cascadeOnDelete();
            // Для сортировки списка диалогов без подзапроса к сообщениям.
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['user_one_id', 'user_two_id']);
            $table->index(['user_two_id', 'last_message_at']);
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('text');
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'id']);
        });

        Schema::create('conversation_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });

        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            // На кого/на что жалуются: игрок или переписка.
            $table->string('reportable_type');
            $table->unsignedBigInteger('reportable_id');
            $table->string('reason');
            $table->text('comment')->nullable();
            $table->string('status')->default('new');
            $table->timestamps();

            $table->index(['reportable_type', 'reportable_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reports');
        Schema::dropIfExists('conversation_reads');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('user_blocks');
        Schema::dropIfExists('player_follows');
    }
};
