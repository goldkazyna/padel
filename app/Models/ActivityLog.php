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

    /**
     * Быстрый метод логирования
     */
    public static function log(string $action, string $subjectType, ?int $subjectId, ?string $description = null, ?array $changes = null): self
    {
        $user = auth()->user();
        $clubId = null;

        if ($user) {
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
            'description' => $description,
            'changes' => $changes,
        ]);
    }
}
