<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        return $user->adminClubs()->first();
    }

    public function index(Request $request)
    {
        $club = $this->getClub();
        if (!$club) return redirect()->route('club.dashboard')->with('error', 'Клуб не найден');

        $query = ActivityLog::where('club_id', $club->id)
            ->with('user')
            ->orderByDesc('created_at');

        if ($request->get('action')) {
            $query->where('action', $request->get('action'));
        }

        if ($request->get('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        // Фильтр по менеджеру (модератору клуба) — тоже по user_id
        if ($request->get('manager_id')) {
            $query->where('user_id', $request->get('manager_id'));
        }

        if ($request->get('subject')) {
            $query->where('subject_type', $request->get('subject'));
        }

        if ($request->get('search')) {
            $query->where('description', 'like', '%' . $request->get('search') . '%');
        }

        $date = $request->get('date');
        if ($date) {
            // Фильтр по локальной дате (Asia/Almaty, +5): переводим выбранный
            // день в UTC-диапазон, т.к. created_at хранится в UTC.
            $start = \Carbon\Carbon::parse($date, 'Asia/Almaty')->startOfDay()->utc();
            $end = \Carbon\Carbon::parse($date, 'Asia/Almaty')->endOfDay()->utc();
            $query->whereBetween('created_at', [$start, $end]);
        }

        $logs = $query->paginate(30);

        // Группировка по дням для view — в локальном времени (+5), иначе записи
        // после 19:00 UTC уезжали бы в следующий день.
        $groupedLogs = $logs->getCollection()->groupBy(fn($log) => $log->created_at->timezone('Asia/Almaty')->format('Y-m-d'));

        // Статистика (за всё время, без фильтров)
        $baseQuery = ActivityLog::where('club_id', $club->id);
        $stats = [
            'total' => $baseQuery->count(),
            'created' => (clone $baseQuery)->where('action', 'created')->count(),
            'updated' => (clone $baseQuery)->where('action', 'updated')->count(),
            // В «Отменено» включаем и админскую отмену брони, и самоотмену с турнира
            'cancelled' => (clone $baseQuery)->whereIn('action', ['cancelled', 'unregistered'])->count(),
            'blocked' => (clone $baseQuery)->whereIn('action', ['blocked', 'unblocked'])->count(),
        ];

        // Счётчики по типам объектов
        $subjectCounts = [
            'CourtBooking' => (clone $baseQuery)->where('subject_type', 'CourtBooking')->count(),
            'ClubClient' => (clone $baseQuery)->where('subject_type', 'ClubClient')->count(),
            'CourtBlock' => (clone $baseQuery)->where('subject_type', 'CourtBlock')->count(),
            'Tournament' => (clone $baseQuery)->where('subject_type', 'Tournament')->count(),
            'User' => (clone $baseQuery)->where('subject_type', 'User')->count(),
        ];

        // Пользователи клуба для фильтра
        $users = ActivityLog::where('club_id', $club->id)
            ->select('user_id')
            ->distinct()
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter();

        // Менеджеры (модераторы) клуба для отдельного фильтра
        $managers = $club->moderators()->orderBy('name')->get();

        return view('club.activity-log.index', compact('logs', 'groupedLogs', 'users', 'managers', 'club', 'stats', 'subjectCounts', 'date'));
    }
}
