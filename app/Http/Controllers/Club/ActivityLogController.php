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

        if ($request->get('subject')) {
            $query->where('subject_type', $request->get('subject'));
        }

        if ($request->get('search')) {
            $query->where('description', 'like', '%' . $request->get('search') . '%');
        }

        $logs = $query->paginate(30);

        // Группировка по дням для view
        $groupedLogs = $logs->getCollection()->groupBy(fn($log) => $log->created_at->format('Y-m-d'));

        // Статистика (за всё время, без фильтров)
        $baseQuery = ActivityLog::where('club_id', $club->id);
        $stats = [
            'total' => $baseQuery->count(),
            'created' => (clone $baseQuery)->where('action', 'created')->count(),
            'updated' => (clone $baseQuery)->where('action', 'updated')->count(),
            'cancelled' => (clone $baseQuery)->where('action', 'cancelled')->count(),
            'blocked' => (clone $baseQuery)->whereIn('action', ['blocked', 'unblocked'])->count(),
        ];

        // Счётчики по типам объектов
        $subjectCounts = [
            'CourtBooking' => (clone $baseQuery)->where('subject_type', 'CourtBooking')->count(),
            'ClubClient' => (clone $baseQuery)->where('subject_type', 'ClubClient')->count(),
            'CourtBlock' => (clone $baseQuery)->where('subject_type', 'CourtBlock')->count(),
        ];

        // Пользователи клуба для фильтра
        $users = ActivityLog::where('club_id', $club->id)
            ->select('user_id')
            ->distinct()
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter();

        return view('club.activity-log.index', compact('logs', 'groupedLogs', 'users', 'club', 'stats', 'subjectCounts'));
    }
}
