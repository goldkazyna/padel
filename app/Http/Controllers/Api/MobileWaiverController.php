<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\ClubWaiverSignature;
use App\Services\WaiverSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Отказ от ответственности в приложении: чтение текста и подпись.
 */
class MobileWaiverController extends Controller
{
    public function show(Request $request, Club $club): JsonResponse
    {
        $signature = ClubWaiverSignature::where('club_id', $club->id)
            ->where('user_id', $request->user()->id)
            ->first();

        return response()->json([
            'success' => true,
            'collects' => $club->collectsWaiver(),
            'club_name' => $club->name,
            'text' => $club->collectsWaiver() ? $club->waiver_text : null,
            'text_hash' => $club->waiverTextHash(),
            'signed_at' => $signature?->signed_at?->toIso8601String(),
            'full_name' => $signature?->full_name,
            'signed_text' => $signature?->waiver_text,
        ]);
    }

    public function sign(Request $request, Club $club, WaiverSignatureService $service): JsonResponse
    {
        if (!$club->collectsWaiver()) {
            return response()->json([
                'success' => false,
                'message' => 'Клуб не собирает отказ от ответственности',
            ], 422);
        }

        $validated = $request->validate([
            'full_name' => 'required|string|min:3|max:255',
            'text_hash' => 'required|string',
            'signature' => 'required|string',
        ]);

        // Текст успели поправить, пока человек читал: подписывать старую
        // редакцию нельзя, отдаём свежую и просим перечитать.
        if (!hash_equals((string) $club->waiverTextHash(), $validated['text_hash'])) {
            return response()->json([
                'success' => false,
                'message' => 'Текст изменился, перечитайте его',
                'text' => $club->waiver_text,
                'text_hash' => $club->waiverTextHash(),
            ], 409);
        }

        try {
            $signature = $service->store(
                $club,
                $request->user(),
                trim($validated['full_name']),
                $validated['signature'],
                $request
            );
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'signed_at' => $signature->signed_at->toIso8601String(),
        ]);
    }
}
