<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClubCard;
use App\Models\ClubClient;
use App\Models\CourtBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Клубные карты в приложении (только чтение).
 *
 * Связка user ↔ клиент клуба ↔ карты — по НОМЕРУ ТЕЛЕФОНА: у карт нет
 * прямой ссылки на users, но телефон юзера подтверждён по SMS, поэтому
 * показываем карты клиентов клубов с тем же номером (сверка по последним
 * 10 цифрам, чтобы не зависеть от формата +7 / 8 / пробелов).
 */
class MobileClubCardController extends Controller
{
    /** GET /api/mobile/club-cards — карты пользователя, сгруппированные по клубам. */
    public function index(Request $request): JsonResponse
    {
        $last10 = $this->userPhoneLast10($request);
        if ($last10 === null) {
            return response()->json(['clubs' => []]);
        }

        $clientIds = $this->matchingClientIds($last10);
        if (empty($clientIds)) {
            return response()->json(['clubs' => []]);
        }

        $cards = ClubCard::whereIn('club_client_id', $clientIds)
            ->with(['type', 'club'])
            ->orderByDesc('id')
            ->get();

        // Группировка по клубу с сохранением порядка появления.
        $grouped = [];
        foreach ($cards as $card) {
            $clubId = $card->club_id;
            if (!isset($grouped[$clubId])) {
                $grouped[$clubId] = [
                    'club' => [
                        'id' => $card->club?->id,
                        'name' => $card->club?->name,
                        'logo' => $card->club?->logo ? url($card->club->logo) : null,
                        'address' => $card->club?->address,
                        'card_bg_color' => $card->club?->card_bg_color,
                        'card_accent_color' => $card->club?->card_accent_color,
                        'card_progress_color' => $card->club?->card_progress_color,
                    ],
                    'active_count' => 0,
                    'total_count' => 0,
                    'cards' => [],
                ];
            }
            $grouped[$clubId]['cards'][] = $this->formatCard($card);
            $grouped[$clubId]['total_count']++;
            if ($card->isActual()) {
                $grouped[$clubId]['active_count']++;
            }
        }

        return response()->json(['clubs' => array_values($grouped)]);
    }

    /** GET /api/mobile/club-cards/{card} — детали карты + история операций. */
    public function show(Request $request, ClubCard $card): JsonResponse
    {
        if (!$this->ownsCard($request, $card)) {
            return response()->json(['message' => 'Карта не найдена'], 404);
        }

        $card->load(['type', 'club', 'transactions']);

        return response()->json([
            'card' => $this->formatCard($card, withClub: true),
            'transactions' => $card->transactions->map(fn($t) => [
                'id' => $t->id,
                'amount' => (int) $t->amount,          // сколько списано
                'balance_after' => $t->balance_after !== null ? (int) $t->balance_after : null,
                'note' => $t->note,
                'has_booking' => $t->court_booking_id !== null,
                'created_at' => optional($t->created_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    /** GET /api/mobile/club-cards/{card}/bookings — будущие брони по карте. */
    public function bookings(Request $request, ClubCard $card): JsonResponse
    {
        if (!$this->ownsCard($request, $card)) {
            return response()->json(['message' => 'Карта не найдена'], 404);
        }

        $bookings = CourtBooking::where('club_card_id', $card->id)
            ->where('status', '!=', 'cancelled')
            ->whereDate('date', '>=', now()->toDateString())
            ->with(['court.club'])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'bookings' => $bookings->map(function ($b) {
                [$canCancel, $cancelHours] = $this->cancelInfo($b);
                return [
                    'id' => $b->id,
                    'date' => optional($b->date)->toDateString(),
                    'start_time' => $this->hm($b->start_time),
                    'end_time' => $this->hm($b->end_time),
                    'court_name' => $b->court?->name,
                    'club_name' => $b->court?->club?->name,
                    'status' => $b->status,
                    'can_cancel' => $canCancel,
                    'cancel_min_hours' => $cancelHours,
                ];
            })->values(),
        ]);
    }

    // ================= helpers =================

    private function formatCard(ClubCard $card, bool $withClub = false): array
    {
        $data = [
            'id' => $card->id,
            'code' => $card->code,
            'type_name' => $card->type?->name,
            'kind' => $card->type?->kind,
            'kind_name' => $card->type?->kindName(),
            'is_counter' => $card->isCounter(),
            'balance' => $card->balance !== null ? (int) $card->balance : null,
            'initial_balance' => $card->initial_balance !== null ? (int) $card->initial_balance : null,
            'discount_percent' => $card->type?->discount_percent !== null
                ? (int) $card->type->discount_percent : null,
            'expires_at' => optional($card->expires_at)->toDateString(),
            'is_expired' => $card->isExpired(),
            'is_actual' => $card->isActual(),
            'status' => $card->status,
        ];

        if ($withClub) {
            $data['club'] = [
                'id' => $card->club?->id,
                'name' => $card->club?->name,
                'logo' => $card->club?->logo ? url($card->club->logo) : null,
                'address' => $card->club?->address,
                'card_bg_color' => $card->club?->card_bg_color,
                'card_accent_color' => $card->club?->card_accent_color,
                'card_progress_color' => $card->club?->card_progress_color,
            ];
        }

        return $data;
    }

    /** Последние 10 цифр телефона авторизованного пользователя (или null). */
    private function userPhoneLast10(Request $request): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $request->user()?->phone);
        if (strlen($digits) < 10) {
            return null;
        }
        return substr($digits, -10);
    }

    /** id клиентов клубов, чей телефон совпадает по последним 10 цифрам. */
    private function matchingClientIds(string $last10): array
    {
        // Сужаем выборку по хвосту номера (терпимо к формату), затем точная
        // сверка последних 10 цифр в PHP.
        $tail = substr($last10, -8);

        return ClubClient::whereNotNull('phone')
            ->where('phone', 'like', '%' . $tail . '%')
            ->pluck('phone', 'id')
            ->filter(fn($phone) => substr(preg_replace('/\D+/', '', (string) $phone), -10) === $last10)
            ->keys()
            ->all();
    }

    /** Принадлежит ли карта текущему пользователю (по телефону клиента). */
    private function ownsCard(Request $request, ClubCard $card): bool
    {
        $last10 = $this->userPhoneLast10($request);
        if ($last10 === null) {
            return false;
        }
        $clientPhone = $card->client()->value('phone');
        if ($clientPhone === null) {
            return false;
        }
        return substr(preg_replace('/\D+/', '', (string) $clientPhone), -10) === $last10;
    }

    /** «19:00:00» → «19:00». */
    private function hm(?string $time): ?string
    {
        if ($time === null) {
            return null;
        }
        return substr($time, 0, 5);
    }

    /** [можно ли отменить бронь, лимит часов] по настройке клуба (0 — без лимита). */
    private function cancelInfo(CourtBooking $b): array
    {
        $hours = (int) ($b->court?->club?->booking_cancel_hours ?? 2);
        if ($b->status !== 'confirmed') {
            return [false, $hours];
        }
        $startDt = \Carbon\Carbon::parse(
            optional($b->date)->format('Y-m-d') . ' ' .
            \Carbon\Carbon::parse($b->start_time)->format('H:i:s')
        );
        $hoursUntilStart = now()->diffInMinutes($startDt, false) / 60.0;
        $can = $hours <= 0 || $hoursUntilStart >= $hours;
        return [$can, $hours];
    }
}
