<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Справочник контактов клуба: персонал, поставщики, кто угодно.
     *
     * Группы заводит сам клуб — набор у всех разный, зашивать список
     * в код значило бы через месяц дописывать в него «электрик».
     */
    public function up(): void
    {
        Schema::create('contact_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['club_id', 'sort_order']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            // Группу можно удалить, не потеряв людей — они станут «без группы».
            $table->foreignId('contact_group_id')->nullable()
                ->constrained('contact_groups')->nullOnDelete();
            $table->string('name');
            $table->string('position')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'contact_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('contact_groups');
    }
};
