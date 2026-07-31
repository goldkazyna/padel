<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CertificateTemplate extends Model
{
    protected $fillable = [
        'club_id', 'name', 'number_prefix', 'heading', 'subtitle_named', 'subtitle_generic',
        'body_text', 'background_color', 'accent_color', 'border_color',
        'text_color', 'logo_path', 'orientation', 'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
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
}
