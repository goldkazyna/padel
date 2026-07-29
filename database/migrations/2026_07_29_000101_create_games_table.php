<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('club_id')->constrained('clubs')->onDelete('cascade');
            $table->foreignId('court_id')->nullable()->constrained('courts')->onDelete('set null');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->enum('type', ['rated', 'friendly'])->default('rated');
            $table->enum('visibility', ['public', 'private'])->default('public');
            $table->enum('format', ['sets', 'points', 'americano'])->default('sets');
            $table->json('format_meta')->nullable();
            $table->decimal('rating_min', 4, 2)->nullable();
            $table->decimal('rating_max', 4, 2)->nullable();
            $table->unsignedTinyInteger('capacity')->default(4);
            $table->integer('price')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['open', 'full', 'in_progress', 'finished', 'cancelled', 'disputed'])->default('open');
            $table->boolean('score_locked')->default(false);
            $table->string('share_token')->nullable()->unique();
            $table->dateTime('share_expires_at')->nullable();
            $table->integer('share_max_uses')->nullable();
            $table->unsignedInteger('share_uses')->default(0);
            $table->dateTime('share_revoked_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('visibility');
            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
