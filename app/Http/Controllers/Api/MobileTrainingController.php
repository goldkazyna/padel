<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Training;
use App\Services\TrainingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Тренировки со стороны игрока: список, запись и свои занятия.
 */
class MobileTrainingController extends Controller
{
    public function __construct(private TrainingService $service)
    {
    }

    /** Ближайшие тренировки, на которые открыта запись. */
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $trainings = Training::upcoming()
            ->with(['club', 'coach', 'participants.user'])
            ->withCount('participants')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Training $t) => $this->format($t, $userId));

        return response()->json([
            'success' => true,
            'trainings' => $trainings->values(),
        ]);
    }

    public function show(Request $request, Training $training): JsonResponse
    {
        $training->loadMissing(['club', 'coach', 'participants.user'])
            ->loadCount('participants');

        return response()->json([
            'success' => true,
            'training' => $this->format($training, (int) $request->user()->id),
        ]);
    }

    public function join(Request $request, Training $training): JsonResponse
    {
        try {
            $this->service->join($training, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    public function leave(Request $request, Training $training): JsonResponse
    {
        $this->service->leave($training, $request->user());

        return response()->json(['success' => true]);
    }

    /** Свои записи: предстоящие и прошедшие. */
    public function my(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $trainings = Training::whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->with(['club', 'coach', 'participants.user'])
            ->withCount('participants')
            ->orderBy('starts_at')
            ->get();

        // Отменённые остаются в предстоящих: игрок должен видеть, что занятие
        // не состоится, а не гадать, куда оно делось из списка.
        [$past, $upcoming] = $trainings->partition(
            fn (Training $t) => $t->starts_at->isPast()
        );

        return response()->json([
            'success' => true,
            'upcoming' => $upcoming->map(fn ($t) => $this->format($t, $userId))->values(),
            'past' => $past->sortByDesc('starts_at')
                ->map(fn ($t) => $this->format($t, $userId))->values(),
        ]);
    }

    /** Числа для бейджей: на плитке главной и на кнопке в профиле. */
    public function count(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $upcoming = Training::upcoming()
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->count();

        $available = Training::upcoming()
            ->withCount('participants')
            ->get()
            ->filter(fn (Training $t) => $t->participants_count < $t->capacity)
            ->count();

        return response()->json([
            'success' => true,
            'upcoming' => $upcoming,
            'available' => $available,
        ]);
    }

    private function format(Training $training, int $userId): array
    {
        $taken = $training->participants_count ?? $training->participants()->count();
        $isJoined = $training->participants()->where('user_id', $userId)->exists();

        return [
            'id' => $training->id,
            'coach' => [
                'id' => $training->coach?->id,
                'name' => $training->coach?->name,
                'avatar' => $training->coach?->avatar,
            ],
            'club' => [
                'id' => $training->club?->id,
                'name' => $training->club?->name,
                'city' => $training->club?->city,
                // Логотип показываем в карточке рядом с названием площадки.
                'logo' => $training->club?->logo ? url($training->club->logo) : null,
            ],
            'starts_at' => $training->starts_at->format('Y-m-d H:i'),
            'date' => $training->starts_at->translatedFormat('j F'),
            'time' => $training->starts_at->format('H:i'),
            'duration_minutes' => (int) $training->duration_minutes,
            'price' => (int) $training->price,
            'capacity' => (int) $training->capacity,
            'participants_count' => (int) $taken,
            'free_slots' => max(0, (int) $training->capacity - (int) $taken),
            'description' => $training->description,
            'status' => $training->status,
            'is_joined' => $isJoined,
            'can_join' => !$isJoined && $training->isOpenForJoin(),
            // Занятые места для кружков-слотов. Телефонов здесь нет — их
            // видит только тренер в своём кабинете.
            'participants' => $training->participants->map(fn ($p) => [
                'id' => $p->user?->id,
                'name' => $p->user?->name,
                'avatar' => $p->user?->avatar,
                'is_me' => (int) ($p->user?->id ?? 0) === $userId,
            ])->filter(fn ($row) => $row['id'] !== null)->values(),
        ];
    }
}
