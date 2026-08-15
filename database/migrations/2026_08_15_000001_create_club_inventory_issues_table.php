<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Факт выдачи инвентаря клиенту на руки. Не продажа: денег не касается,
        // в кассу и отчёты не идёт — только учёт того, что ушло с полки.
        Schema::create('club_inventory_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_client_id')->constrained()->cascadeOnDelete();
            // Кто выдал. Сотрудника могут удалить — выдача при этом остаётся.
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamps();

            // Карточки клиентов на экране строятся по невозвращённым позициям
            // этого клуба — выбираем сразу по паре.
            $table->index(['club_id', 'club_client_id']);
        });

        // Строка выдачи. Возврат отмечается здесь, а не на выдаче целиком:
        // клиент может вернуть ракетки и оставить себе мячи.
        Schema::create('club_inventory_issue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_inventory_issue_id')
                ->constrained('club_inventory_issues')
                ->cascadeOnDelete();
            // Позицию справочника могут удалить — строка выдачи должна пережить это,
            // поэтому название и цена лежат снимком, как в инвентаре брони.
            $table->foreignId('club_inventory_item_id')
                ->nullable()
                ->constrained('club_inventory_items')
                ->nullOnDelete();
            $table->string('name');
            $table->integer('price')->default(0);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Красный бейдж на плитке считает невозвращённое по позиции.
            $table->index(['club_inventory_item_id', 'returned_at'], 'inv_issue_item_open_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_inventory_issue_items');
        Schema::dropIfExists('club_inventory_issues');
    }
};
