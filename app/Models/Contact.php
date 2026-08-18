<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Контакт клуба. Без группы тоже живёт — группу могли удалить. */
class Contact extends Model
{
    protected $fillable = [
        'club_id', 'contact_group_id', 'name', 'position', 'phone', 'email', 'note',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function group()
    {
        return $this->belongsTo(ContactGroup::class, 'contact_group_id');
    }

    /** Поиск по имени, должности, телефону, почте и заметке. */
    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $digits = preg_replace('/\D/', '', $term);

        return $query->where(function ($q) use ($term, $digits) {
            foreach (['name', 'position', 'email', 'note'] as $field) {
                $q->orWhere($field, 'like', "%{$term}%");
            }
            // По телефону ищем цифрами: номера записывают как попало.
            if ($digits !== '') {
                $q->orWhereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '(', ''), ')', ''), '-', '') LIKE ?",
                    ['%' . $digits . '%']
                );
            }
        });
    }
}
