<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class MobileNotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = Notification::where('user_id', $request->user()->id);

        if ($request->has('category')) {
            $query->where('category', $request->get('category'));
        }

        $notifications = $query->orderByDesc('created_at')->simplePaginate(20);

        return response()->json($notifications);
    }

    public function unreadCount(Request $request)
    {
        $count = Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    public function readAll(Request $request)
    {
        $query = Notification::where('user_id', $request->user()->id)->whereNull('read_at');

        if ($request->has('category')) {
            $query->where('category', $request->get('category'));
        }

        $query->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function categories(Request $request)
    {
        $userId = $request->user()->id;

        $cats = [
            ['key' => 'tournament', 'color' => '#22C55E'],
            ['key' => 'booking', 'color' => '#3B82F6'],
            ['key' => 'challenge', 'color' => '#FB923C'],
            ['key' => 'support', 'color' => '#F43F5E'],
            ['key' => 'achievement', 'color' => '#EAB34E'],
            ['key' => 'general', 'color' => '#A78BFA'],
        ];

        $result = [];
        foreach ($cats as $cat) {
            $unread = Notification::where('user_id', $userId)
                ->where('category', $cat['key'])
                ->whereNull('read_at')
                ->count();

            $last = Notification::where('user_id', $userId)
                ->where('category', $cat['key'])
                ->orderByDesc('created_at')
                ->first();

            $total = Notification::where('user_id', $userId)
                ->where('category', $cat['key'])
                ->count();

            $result[] = [
                'key' => $cat['key'],
                'color' => $cat['color'],
                'unread' => $unread,
                'total' => $total,
                'last_title' => $last?->title,
                'last_body' => $last?->body,
                'last_at' => $last?->created_at?->toISOString(),
            ];
        }

        return response()->json(['success' => true, 'categories' => $result]);
    }
}
