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

        $logs = $query->paginate(30);

        // Пользователи клуба для фильтра
        $users = ActivityLog::where('club_id', $club->id)
            ->select('user_id')
            ->distinct()
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter();

        return view('club.activity-log.index', compact('logs', 'users', 'club'));
    }
}
