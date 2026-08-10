<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Шаблон чек-листа клуба: пункты открытия и закрытия смены.
        Schema::create('shift_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['opening', 'closing']);
            $table->string('title', 500);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['club_id', 'type', 'is_active']);
        });

        // Смена менеджера: персональная, у каждого своя.
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            // По этому индексу на каждом запросе ищется открытая смена.
            $table->index(['club_id', 'user_id', 'closed_at']);
        });

        Schema::create('shift_checklist_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->onDelete('cascade');
            // Пункт могут отключить или удалить — ссылка не обязательна.
            $table->foreignId('item_id')->nullable()
                ->constrained('shift_checklist_items')->nullOnDelete();
            $table->enum('type', ['opening', 'closing']);
            // Снимок текста: админ переформулирует пункт — история прошлых
            // смен должна остаться такой, какой была.
            $table->string('title_snapshot', 500);
            $table->boolean('is_done')->default(false);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_checklist_results');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('shift_checklist_items');
    }
};
