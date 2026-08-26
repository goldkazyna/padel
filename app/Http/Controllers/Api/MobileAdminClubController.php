<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileAdminClubController extends Controller
{
    private function canEditClub($user, Club $club): bool
    {
        if (!$user) return false;
        if ($user->isSuperAdmin()) return true;
        return $user->adminClubs()->where('clubs.id', $club->id)->exists();
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Нет доступа к этому клубу'], 403);
    }

    private function payload(Club $club): array
    {
        return [
            'id' => $club->id,
            'name' => $club->name,
            'address' => $club->address,
            'city' => $club->city,
            'phone' => $club->phone,
            'email' => $club->email,
            'description' => $club->description,
            'payment_url' => $club->payment_url,
            'telegram_url' => $club->telegram_url,
            'instagram_url' => $club->instagram_url,
        ];
    }

    public function show(Request $request, Club $club): JsonResponse
    {
        if (!$this->canEditClub($request->user(), $club)) {
            return $this->forbidden();
        }
        return response()->json(['success' => true, 'club' => $this->payload($club)]);
    }

    public function update(Request $request, Club $club): JsonResponse
    {
        if (!$this->canEditClub($request->user(), $club)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'city' => 'nullable|string|in:' . implode(',', config('cities')),
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'description' => 'nullable|string',
            'payment_url' => 'nullable|url|max:500',
            'telegram_url' => 'nullable|url|max:500',
            'instagram_url' => 'nullable|url|max:500',
        ]);

        $club->update($validated);

        return response()->json(['success' => true, 'club' => $this->payload($club->fresh())]);
    }
}
