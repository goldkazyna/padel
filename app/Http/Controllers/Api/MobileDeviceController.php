<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileDeviceController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'platform' => 'required|in:android,ios',
        ]);

        DeviceToken::updateOrCreate(
            ['token' => $request->token],
            [
                'user_id' => $request->user()->id,
                'platform' => $request->platform,
            ]
        );

        return response()->json(['success' => true]);
    }

    public function getSettings(Request $request): JsonResponse
    {
        $user = $request->user();

        // Список всех активных клубов с турнирами
        $clubs = \App\Models\Club::active()
            ->has('tournaments')
            ->select('id', 'name', 'logo')
            ->orderBy('name')
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'logo' => $c->logo ? url($c->logo) : null,
            ]);

        return response()->json([
            'notify_only_my_level' => (bool) $user->notify_only_my_level,
            'notify_club_ids' => $user->notify_club_ids,
            'clubs' => $clubs,
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notify_only_my_level' => 'sometimes|boolean',
            'notify_club_ids' => 'sometimes|nullable|array',
            'notify_club_ids.*' => 'integer|exists:clubs,id',
        ]);

        $request->user()->update($validated);

        return response()->json(['success' => true]);
    }
}
