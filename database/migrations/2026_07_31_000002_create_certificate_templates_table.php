<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->onDelete('cascade');
            $table->string('name')->default('Основной');
            $table->string('heading')->default('Сертификат');
            $table->string('subtitle_named')->default('Настоящим удостоверяется, что');
            $table->string('subtitle_generic')->default('Настоящий сертификат выдан');
            $table->text('body_text')->nullable();
            $table->string('background_color', 20)->default('#fbfaf6');
            $table->string('accent_color', 20)->default('#c9a24b');
            $table->string('border_color', 20)->default('#1f6b3b');
            $table->string('text_color', 20)->default('#14532d');
            $table->string('logo_path')->nullable();
            $table->string('orientation', 20)->default('landscape');
            $table->boolean('is_default')->default(true);
            $table->timestamps();

            $table->index('club_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_templates');
    }
};
