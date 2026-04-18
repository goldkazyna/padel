<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\CourtPriceRange;
use Illuminate\Http\Request;

class MobileClubController extends Controller
{
    /**
     * Список всех активных клубов
     * GET /api/mobile/clubs?search=...&city=...
     */
    public function index(Request $request)
    {
        $query = Club::active();

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

        $clubs = $query->orderBy('name')->get();

        $result = $clubs->map(fn($club) => [
            'id' => $club->id,
            'name' => $club->name,
            'address' => $club->address,
            'city' => $club->city,
            'logo' => $club->logo ? url($club->logo) : null,
            'description' => $club->description,
            'phone' => $club->phone,
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
            ],
        ]);
    }
}
