<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CertificateTemplate extends Model
{
    protected $fillable = [
        'club_id', 'name', 'number_prefix', 'heading', 'subtitle_named', 'subtitle_generic',
        'body_text', 'background_color', 'accent_color', 'border_color',
        'text_color', 'logo_path', 'orientation', 'is_default',
        'background_image_path', 'layout',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'layout' => 'array',
    ];

    /** Дефолтные позиции полей (%, размер в px при ширине 1000, цвет, выравнивание). */
    public const DEFAULT_LAYOUT = [
        'name' => ['x' => 22, 'y' => 47, 'size' => 42, 'color' => '#1e2a44', 'align' => 'left'],
        'value' => ['x' => 24, 'y' => 56, 'size' => 34, 'color' => '#334155', 'align' => 'left'],
        'number' => ['x' => 33, 'y' => 63, 'size' => 26, 'color' => '#334155', 'align' => 'left'],
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class, 'template_id');
    }

    /** Дефолтный шаблон клуба (создаётся при первом обращении). */
    public static function defaultForClub(int $clubId): self
    {
        return static::firstOrCreate(
            ['club_id' => $clubId, 'is_default' => true],
            ['name' => 'Основной']
        );
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? asset('storage/' . $this->logo_path) : null;
    }

    public function backgroundImageUrl(): ?string
    {
        return $this->background_image_path
            ? asset('storage/' . $this->background_image_path)
            : null;
    }

    /** true — клуб загрузил свою картинку-фон (режим v2). */
    public function hasBackgroundImage(): bool
    {
        return !empty($this->background_image_path);
    }

    /** Позиции полей: сохранённые или дефолтные. */
    public function fieldLayout(): array
    {
        $layout = is_array($this->layout) ? $this->layout : [];
        $out = [];
        foreach (self::DEFAULT_LAYOUT as $key => $def) {
            $out[$key] = array_merge($def, $layout[$key] ?? []);
        }
        return $out;
    }
}
