<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Должностные инструкции клуба.
 *
 * У каждого клуба свои: разделы («Открытие смены», «Брони», «Турниры») и
 * инструкции внутри них. Тексты пишет админ клуба, читают ещё и менеджеры
 * смены — им это нужно прямо во время работы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_instruction_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['club_id', 'sort_order']);
        });

        Schema::create('club_instructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')
                ->constrained('club_instruction_sections')
                ->cascadeOnDelete();
            $table->string('title');
            // Размеченный текст: заголовки, списки, выделение, картинки.
            $table->longText('body')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['club_id', 'section_id', 'sort_order']);
        });

        Schema::create('club_instruction_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instruction_id')
                ->constrained('club_instructions')
                ->cascadeOnDelete();
            // Путь внутри public: «/club_instructions/6/xxx.pdf».
            $table->string('path');
            $table->string('name');
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->boolean('is_image')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_instruction_files');
        Schema::dropIfExists('club_instructions');
        Schema::dropIfExists('club_instruction_sections');
    }
};
