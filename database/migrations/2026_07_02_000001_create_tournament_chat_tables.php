<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->timestamp('created_at')->nullable();

            $table->index(['tournament_id', 'id']);
        });

        Schema::create('tournament_chat_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamps();

            $table->unique(['tournament_id', 'user_id']);
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->boolean('chat_enabled')->default(true);
            // admin | participants | everyone
            $table->string('chat_write_mode')->default('participants');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['chat_enabled', 'chat_write_mode']);
        });
        Schema::dropIfExists('tournament_chat_reads');
        Schema::dropIfExists('tournament_chat_messages');
    }
};
