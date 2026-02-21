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
        return response()->json([
            'notify_only_my_level' => (bool) $request->user()->notify_only_my_level,
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notify_only_my_level' => 'required|boolean',
        ]);

        $request->user()->update($validated);

        return response()->json(['success' => true]);
    }
}
