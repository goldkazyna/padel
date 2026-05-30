<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\CourtPriceRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MobileClubController extends Controller
{
    /**
     * Список всех активных клубов
     * GET /api/mobile/clubs?search=...&city=...
     */
    public function index(Request $request)
    {
        $query = Club::active()->notTest();

        // type: 'club' (default) — без флага сообществ; 'community' — только
        // комьюнити; 'all' — без фильтра.
        $type = $request->get('type', 'club');
        if ($type === 'community') {
            $query->where('is_community', true);
        } elseif ($type === 'club') {
            $query->where(function ($q) {
                $q->where('is_community', false)->orWhereNull('is_community');
            });
        }

        if ($request->filled('city')) {
            $query->where('city', $request->city);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%");
            });
        }

        $clubs = $query->orderBy('id')->get();

        $result = $clubs->map(fn($club) => [
            'id' => $club->id,
            'name' => $club->name,
            'address' => $club->address,
            'city' => $club->city,
            'logo' => $club->logo ? url($club->logo) : null,
            'description' => $club->description,
            'phone' => $club->phone,
            'is_community' => (bool) $club->is_community,
            'coming_soon' => (bool) $club->coming_soon,
            'telegram_url' => $club->telegram_url,
            'instagram_url' => $club->instagram_url,
        ]);

        $cities = $clubs->pluck('city')->filter()->unique()->sort()->values();

        return response()->json([
            'success' => true,
            'clubs' => $result,
            'cities' => $cities,
        ]);
    }

    /**
     * Карточка клуба
     * GET /api/mobile/clubs/{club}
     */
    public function show(Club $club)
    {
        if (!$club->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Клуб недоступен',
            ], 404);
        }

        $courtsCount = $club->courts()->where('is_active', true)->count();

        $minPrice = CourtPriceRange::whereHas('court', function ($q) use ($club) {
                $q->where('club_id', $club->id)->where('is_active', true);
            })
            ->min('price');

        $user = Auth::user();
        $hiddenIds = $user ? ($user->hidden_club_ids ?? []) : [];
        $isHidden = in_array($club->id, $hiddenIds, true);

        // Тренеры клуба — показываем только тех, у кого есть фото
        $coaches = $club->clubCoaches()
            ->with('user:id,name,first_name,last_name')
            ->whereNotNull('photo')
            ->where('photo', '!=', '')
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->user?->full_name ?: $c->user?->name,
                'specialization' => $c->specialization,
                'photo' => url($c->photo),
                'rating' => $c->rating,
            ])
            ->filter(fn($c) => !empty($c['name']))
            ->values();

        return response()->json([
            'success' => true,
            'club' => [
                'id' => $club->id,
                'name' => $club->name,
                'address' => $club->address,
                'city' => $club->city,
                'logo' => $club->logo ? url($club->logo) : null,
                'description' => $club->description,
                'phone' => $club->phone,
                'email' => $club->email,
                'courts_count' => $courtsCount,
                'min_price' => $minPrice !== null ? (float) $minPrice : null,
                'is_hidden' => $isHidden,
                'telegram_url' => $club->telegram_url,
                'instagram_url' => $club->instagram_url,
                'cover' => $club->cover ? url($club->cover) : null,
                'is_community' => (bool) $club->is_community,
                'coming_soon' => (bool) $club->coming_soon,
                'open_tournaments_count' => $club->tournaments()
                    ->where('status', 'open')
                    ->where('start_date', '>', now())
                    ->count(),
                'coaches' => $coaches,
            ],
        ]);
    }

    /**
     * Переключить скрытие турниров клуба для пользователя
     * POST /api/mobile/clubs/{club}/toggle-hide
     */
    public function toggleHide(Club $club)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $hidden = $user->hidden_club_ids ?? [];
        if (in_array($club->id, $hidden, true)) {
            $hidden = array_values(array_filter($hidden, fn($id) => $id !== $club->id));
        } else {
            $hidden[] = $club->id;
        }

        $user->hidden_club_ids = array_values(array_unique($hidden));
        $user->save();

        return response()->json([
            'success' => true,
            'is_hidden' => in_array($club->id, $user->hidden_club_ids, true),
        ]);
    }
}
