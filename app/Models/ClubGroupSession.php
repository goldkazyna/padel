<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class ClubGroupSession extends Model
{
    /** Бейдж «К списанию» учитывает занятия не раньше этой даты (старые игнорируем). */
    public const PENDING_SINCE = '2026-06-22';

    protected $fillable = [
        'group_id', 'court_id', 'court_booking_id', 'date', 'start_time', 'end_time',
        'coach_id', 'status', 'held_at', 'conducted_by',
    ];

    protected $casts = [
        'date' => 'date',
        'held_at' => 'datetime',
    ];

    public function group() { return $this->belongsTo(ClubGroup::class, 'group_id'); }
    public function court() { return $this->belongsTo(Court::class, 'court_id'); }
    public function coach() { return $this->belongsTo(User::class, 'coach_id'); }
    public function courtBooking() { return $this->belongsTo(CourtBooking::class, 'court_booking_id'); }
    public function attendance() { return $this->hasMany(ClubGroupAttendance::class, 'session_id'); }

    /**
     * Кол-во занятий «к списанию»: запланированы, время окончания уже прошло,
     * ещё не проведены, не раньше PENDING_SINCE. Считается тем же способом,
     * что метка «провести» в сетке (Carbon-сравнение в зоне клуба).
     */
    public static function pendingConductCountForClub(Club $club): int
    {
        $courtIds = $club->courts()->pluck('id');
        if ($courtIds->isEmpty()) return 0;

        $now = now('Asia/Almaty');

        return static::whereIn('court_id', $courtIds)
            ->where('status', 'planned')
            ->whereDate('date', '>=', self::PENDING_SINCE)
            ->whereDate('date', '<=', $now->format('Y-m-d'))
            ->get(['id', 'date', 'end_time'])
            ->filter(function ($s) use ($now) {
                $endsAt = Carbon::parse($s->date->format('Y-m-d') . ' ' . $s->end_time, 'Asia/Almaty');
                return $now->gte($endsAt);
            })
            ->count();
    }

    /** То же, но клуб определяется по текущему пользователю (для бейджа в меню). */
    public static function pendingConductCountForCurrentUser(): int
    {
        $user = auth()->user();
        if (!$user) return 0;

        $club = $user->isSuperAdmin()
            ? Club::first()
            : ($user->isClubModerator() ? $user->moderatorClubs()->first() : $user->adminClubs()->first());

        return $club ? self::pendingConductCountForClub($club) : 0;
    }
}
