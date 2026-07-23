<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'club_id',
        'action',
        'subject_type',
        'subject_id',
        'group_id',
        'changes',
        'description',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function group()
    {
        return $this->belongsTo(ClubGroup::class, 'group_id');
    }

    /**
     * Быстрый метод логирования
     *
     * Если $clubId передан явно — используется он (для мобильных/публичных действий).
     * Иначе clubId определяется по роли текущего пользователя (admin-flow).
     */
    public static function log(
        string $action,
        string $subjectType,
        ?int $subjectId,
        ?string $description = null,
        ?array $changes = null,
        ?int $clubId = null,
        ?int $groupId = null,
    ): self {
        $user = auth()->user();

        if ($clubId === null && $user) {
            if ($user->isSuperAdmin()) {
                $clubId = Club::first()?->id;
            } elseif ($user->isClubModerator()) {
                $clubId = $user->moderatorClubs()->first()?->id;
            } elseif ($user->isClubAdmin()) {
                $clubId = $user->adminClubs()->first()?->id;
            }
        }

        return self::create([
            'user_id' => $user?->id,
            'club_id' => $clubId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'group_id' => $groupId,
            'description' => $description,
            'changes' => $changes,
        ]);
    }

    /**
     * Хелпер для событий групп: всегда с group_id и понятным действием.
     * $action — «человеческий» тип события журнала групп (см. GroupJournalController).
     */
    public static function logGroup(
        int $groupId,
        string $action,
        string $subjectType,
        ?int $subjectId,
        string $description,
        ?array $changes = null,
        ?int $clubId = null,
    ): self {
        return self::log($action, $subjectType, $subjectId, $description, $changes, $clubId, $groupId);
    }
}
