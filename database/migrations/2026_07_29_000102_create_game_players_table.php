<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->unsignedTinyInteger('position')->nullable();
            $table->enum('status', ['invited', 'candidate', 'accepted', 'declined', 'left', 'removed'])->default('candidate');
            $table->enum('source', ['creator', 'invite', 'app_feed', 'app_link'])->default('invite');
            $table->boolean('out_of_range')->default(false);
            $table->integer('rating_before')->nullable();
            $table->integer('rating_after')->nullable();
            $table->integer('rating_change')->nullable();
            $table->boolean('score_confirmed')->default(false);
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_players');
    }
};
