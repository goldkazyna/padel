<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Инструкция: что и как делать на конкретном этапе работы клуба. */
class ClubInstruction extends Model
{
    protected $fillable = ['club_id', 'section_id', 'title', 'body', 'sort_order', 'updated_by'];

    protected $casts = ['sort_order' => 'integer'];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function section()
    {
        return $this->belongsTo(ClubInstructionSection::class, 'section_id');
    }

    public function files()
    {
        return $this->hasMany(ClubInstructionFile::class, 'instruction_id')->orderBy('id');
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Первые строки без разметки — для списка. */
    public function excerpt(int $limit = 120): string
    {
        // Пробел вместо тега: иначе «до открытия</h3><ol>» слипается в
        // «до открытияПроверить».
        $plain = strip_tags(preg_replace('/<[^>]+>/', ' ', (string) $this->body));
        $text = trim(preg_replace('/\s+/u', ' ', $plain));

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }
}
