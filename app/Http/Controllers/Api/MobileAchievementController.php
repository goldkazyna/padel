<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AchievementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Значки игрока.
 *
 * Свой профиль пересчитывается при открытии — игрок всегда видит честное
 * состояние. Чужой только читается: чужие карточки открывают часто, а их
 * значки обновит либо крон, либо сам владелец.
 */
class MobileAchievementController extends Controller
{
    public function __construct(private readonly AchievementService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        // Пересчитываем молча: пуши шлёт только команда синхронизации, иначе
        // открытие экрана уведомляло бы о том, что и так на экране.
        $this->service->sync($user);

        return response()->json([
            'success' => true,
            'achievements' => $this->service->forOwner($user),
        ]);
    }

    public function player(User $user): JsonResponse
    {
        return response()->json([
            'success' => true,
            'achievements' => $this->service->forVisitor($user),
        ]);
    }
}
