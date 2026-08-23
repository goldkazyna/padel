<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Training;
use App\Models\User;
use App\Services\TrainingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Кабинет тренера: свои тренировки, участники и управление занятием.
 */
class MobileCoachTrainingController extends Controller
{
    public function __construct(private TrainingService $service)
    {
    }

    /** Тренировки тренера: ближайшие сверху, прошедшие ниже. */
    public function index(Request $request): JsonResponse
    {
        $coach = $this->coach($request);

        $trainings = Training::where('coach_id', $coach->id)
            ->with('club')
            ->withCount('participants')
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (Training $t) => $this->formatTraining($t));

        return response()->json([
            'success' => true,
            'trainings' => $trainings,
        ]);
    }

    /**
     * Клубы для выбора при создании. Комьюнити тренировки не проводят.
     * `search` ищет и по названию, и по городу — тренер набирает то, что помнит.
     */
    public function clubs(Request $request): JsonResponse
    {
        $this->coach($request);

        $search = trim((string) $request->query('search', ''));

        $clubs = Club::where(function ($q) {
                $q->where('is_community', false)->orWhereNull('is_community');
            })
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'city'])
            ->map(fn (Club $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'city' => $c->city,
            ]);

        return response()->json(['success' => true, 'clubs' => $clubs]);
    }

    public function store(Request $request): JsonResponse
    {
        $coach = $this->coach($request);

        $data = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'starts_at' => 'required|date',
            'duration_minutes' => 'required|integer|min:30|max:300',
            'price' => 'required|integer|min:0|max:1000000',
            'capacity' => 'required|integer|min:1|max:32',
            'description' => 'nullable|string|max:2000',
        ]);

        $training = Training::create([
            'coach_id' => $coach->id,
            'club_id' => $data['club_id'],
            // Время приходит как настенное (Алматы) — храним как есть, без
            // конвертации: так же устроены турниры и брони.
            'starts_at' => \Carbon\Carbon::parse($data['starts_at'])->format('Y-m-d H:i:s'),
            'duration_minutes' => $data['duration_minutes'],
            'price' => $data['price'],
            'capacity' => $data['capacity'],
            'description' => $data['description'] ?? null,
            'status' => 'planned',
        ]);

        return response()->json([
            'success' => true,
            'training_id' => $training->id,
        ]);
    }

    /** Детали с участниками: тренеру нужны телефоны для звонка и WhatsApp. */
    public function show(Request $request, Training $training): JsonResponse
    {
        $this->authorizeOwn($request, $training);

        $training->load(['club', 'participants.user']);

        return response()->json([
            'success' => true,
            'training' => array_merge($this->formatTraining($training), [
                'participants' => $training->participants->map(fn ($p) => [
                    'id' => $p->user?->id,
                    'name' => $p->user?->name,
                    'phone' => $p->user?->phone,
                    'avatar' => $p->user?->avatar,
                    'rating' => (int) ($p->user?->rating ?? 0),
                    'joined_at' => $p->created_at?->format('Y-m-d H:i'),
                ])->filter(fn ($row) => $row['id'] !== null)->values(),
            ]),
        ]);
    }

    public function update(Request $request, Training $training): JsonResponse
    {
        $this->authorizeOwn($request, $training);

        if (!$training->isPlanned()) {
            return $this->error('Завершённую или отменённую тренировку менять нельзя');
        }

        $data = $request->validate([
            'club_id' => 'sometimes|exists:clubs,id',
            'starts_at' => 'sometimes|date',
            'duration_minutes' => 'sometimes|integer|min:30|max:300',
            'price' => 'sometimes|integer|min:0|max:1000000',
            'capacity' => 'sometimes|integer|min:1|max:32',
            'description' => 'nullable|string|max:2000',
        ]);

        if (isset($data['starts_at'])) {
            $data['starts_at'] = \Carbon\Carbon::parse($data['starts_at'])->format('Y-m-d H:i:s');
        }

        $training->update($data);

        return response()->json(['success' => true]);
    }

    public function complete(Request $request, Training $training): JsonResponse
    {
        $this->authorizeOwn($request, $training);

        try {
            $this->service->complete($training);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json(['success' => true]);
    }

    public function cancel(Request $request, Training $training): JsonResponse
    {
        $this->authorizeOwn($request, $training);

        try {
            $this->service->cancel($training);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json(['success' => true]);
    }

    public function removeParticipant(Request $request, Training $training, User $user): JsonResponse
    {
        $this->authorizeOwn($request, $training);

        $this->service->removeParticipant($training, $user);

        return response()->json(['success' => true]);
    }

    // ===================== служебное =====================

    private function coach(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->isCoach(), 403, 'Доступно только тренерам');

        return $user;
    }

    private function authorizeOwn(Request $request, Training $training): void
    {
        $coach = $this->coach($request);
        abort_unless((int) $training->coach_id === (int) $coach->id, 403, 'Это не ваша тренировка');
    }

    private function formatTraining(Training $training): array
    {
        $taken = $training->participants_count ?? $training->participants()->count();

        return [
            'id' => $training->id,
            'club' => [
                'id' => $training->club?->id,
                'name' => $training->club?->name,
                'city' => $training->club?->city,
                // Логотип показываем в карточке рядом с названием площадки,
                // адрес — на экране занятия, там же кнопка «Карта».
                'logo' => $training->club?->logo ? url($training->club->logo) : null,
                'address' => $training->club?->address,
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
            'is_past' => $training->isPast(),
            'can_complete' => $training->isPlanned() && $training->isPast(),
            'can_cancel' => $training->isPlanned(),
        ];
    }

    private function error(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 422);
    }
}
