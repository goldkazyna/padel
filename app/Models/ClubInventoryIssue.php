<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Выдача инвентаря клиенту на руки.
 *
 * Не продажа: в кассу и отчёты не попадает. Задача одна — помнить, что ушло
 * с полки и должно вернуться. Возврат отмечается на строках выдачи
 * (ClubInventoryIssueItem), потому что вернуть могут не всё сразу.
 */
class ClubInventoryIssue extends Model
{
    protected $fillable = [
        'club_id',
        'club_client_id',
        'issued_by',
        'comment',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function client()
    {
        return $this->belongsTo(ClubClient::class, 'club_client_id');
    }

    public function issuedBy()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function items()
    {
        return $this->hasMany(ClubInventoryIssueItem::class);
    }

    /** Невозвращённые строки — по ним и живёт весь раздел. */
    public function openItems()
    {
        return $this->hasMany(ClubInventoryIssueItem::class)->whereNull('returned_at');
    }

    /** Выдача закрыта, когда вернули все её строки. */
    public function isClosed(): bool
    {
        return !$this->items()->whereNull('returned_at')->exists();
    }
}
