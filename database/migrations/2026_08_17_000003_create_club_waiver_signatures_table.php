<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Подпись под отказом от ответственности.
     *
     * Текст и телефон хранятся снимком: клуб текст правит, игрок меняет номер,
     * а доказывать через год нужно то, что человек видел в момент подписи.
     */
    public function up(): void
    {
        Schema::create('club_waiver_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('phone', 32)->nullable();
            $table->text('waiver_text');
            $table->string('signature_path');
            $table->timestamp('signed_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            // Одна подпись на клуб: переподписывать никого не просим.
            $table->unique(['club_id', 'user_id']);
            $table->index(['club_id', 'signed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_waiver_signatures');
    }
};
