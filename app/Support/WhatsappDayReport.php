<?php

namespace App\Support;

use App\Models\CourtBooking;
use App\Models\WhatsappMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Разбор одного дня переписки: точные цифры и выжимка диалогов.
 *
 * Всё, что можно посчитать, считаем здесь и передаём модели как факт.
 * Claude объясняет и ищет упущенное, но не занимается арифметикой —
 * иначе в отчёт попадут красивые, но выдуманные числа.
 */
class WhatsappDayReport
{
    /** Сколько диалогов отдаём модели целиком. */
    private const MAX_DIALOGS = 60;

    /** Предел на одну реплику, чтобы простыня не съела контекст. */
    private const MAX_LINE = 400;

    public static function build(int $clubId, CarbonInterface $day): array
    {
        $tz = WhatsappSla::timezone();
        $start = $day->copy()->timezone($tz)->startOfDay();
        $end = $start->copy()->endOfDay();

        // Захватываем утро следующего дня: на вечернее сообщение отвечают
        // на открытии, и это ответ на вчерашнее обращение, а не молчание.
        $messages = WhatsappMessage::where('club_id', $clubId)
            ->whereBetween('sent_at', [$start->copy()->utc(), $end->copy()->addHours(14)->utc()])
            ->where('chat_id', 'not like', '%@g.us')
            ->where('phone', '<>', '')
            ->whereNotIn('type', ['action', 'system', 'notification'])
            ->orderBy('sent_at')
            ->get(['id', 'phone', 'chat_id', 'from_me', 'sent_at', 'body', 'type', 'author_name']);

        $threshold = WhatsappSla::threshold();
        $dialogs = [];
        $responses = [];
        $unanswered = 0;
        $slow = 0;

        foreach ($messages->groupBy('phone') as $phone => $chat) {
            $ownDay = $chat->filter(fn ($m) => $m->sent_at->between($start->copy()->utc(), $end->copy()->utc()));
            if ($ownDay->where('from_me', false)->isEmpty()) {
                continue;                       // в этот день клиент не писал
            }

            $requests = self::requests($chat, $start, $end);
            $answered = collect($requests)->pluck('minutes')->filter(fn ($m) => $m !== null);

            foreach ($requests as $request) {
                if ($request['minutes'] === null) {
                    $unanswered++;
                } else {
                    $responses[] = $request['minutes'];
                    if ($request['minutes'] > $threshold) {
                        $slow++;
                    }
                }
            }

            $dialogs[] = [
                'phone' => $phone,
                'name' => $ownDay->firstWhere('from_me', false)?->author_name,
                'hidden_number' => !str_contains((string) $ownDay->first()->chat_id, '@s.whatsapp.net'),
                'is_new' => !WhatsappMessage::where('club_id', $clubId)
                    ->where('phone', $phone)
                    ->where('sent_at', '<', $start->copy()->utc())
                    ->exists(),
                'requests' => count($requests),
                'unanswered' => collect($requests)->whereNull('minutes')->count(),
                'worst' => $answered->max(),
                'lines' => self::lines($ownDay, $tz),
            ];
        }

        $dialogs = collect($dialogs)
            // Вперёд — то, что болит: без ответа и самые медленные.
            ->sortByDesc(fn ($d) => [$d['unanswered'], $d['worst'] ?? 0])
            ->values();

        $booked = self::bookedPhones($clubId, $dialogs->pluck('phone')->all(), $start, $end);
        $sorted = collect($responses)->sort()->values();

        return [
            'date' => $start->toDateString(),
            'metrics' => [
                'dialogs' => $dialogs->count(),
                'new_contacts' => $dialogs->where('is_new', true)->count(),
                'requests' => $dialogs->sum('requests'),
                'incoming' => $messages->where('from_me', false)->count(),
                'outgoing' => $messages->where('from_me', true)->count(),
                'answered' => count($responses),
                'unanswered' => $unanswered,
                'slow' => $slow,
                'threshold' => $threshold,
                'median' => $sorted->isEmpty() ? null : (int) round($sorted->median()),
                'average' => $sorted->isEmpty() ? null : (int) round($sorted->avg()),
                'worst' => $sorted->max(),
                'booked' => count($booked),
                'without_booking' => $dialogs->count() - count($booked),
                'work_hours' => WhatsappSla::workFrom() . '-' . WhatsappSla::workTo(),
            ],
            'dialogs' => $dialogs->take(self::MAX_DIALOGS)->map(function ($d) use ($booked) {
                $d['booked'] = in_array(substr($d['phone'], -10), $booked, true);

                return $d;
            })->values()->all(),
        ];
    }

    /**
     * Обращения внутри диалога: серия сообщений клиента и наш первый ответ.
     * Время ответа — в рабочих минутах, ночь не в счёт.
     */
    private static function requests(Collection $chat, CarbonInterface $start, CarbonInterface $end): array
    {
        $requests = [];
        $openedAt = null;
        $from = $start->copy()->utc();
        $to = $end->copy()->utc();

        foreach ($chat as $message) {
            if (!$message->from_me) {
                $openedAt ??= $message->sent_at;
                continue;
            }

            if ($openedAt) {
                if ($openedAt->between($from, $to)) {
                    $requests[] = [
                        'at' => $openedAt,
                        'minutes' => WhatsappSla::businessMinutes($openedAt, $message->sent_at),
                    ];
                }
                $openedAt = null;
            }
        }

        // Осталось висеть без ответа.
        if ($openedAt && $openedAt->between($from, $to)) {
            $requests[] = ['at' => $openedAt, 'minutes' => null];
        }

        return $requests;
    }

    /** Реплики диалога в виде «10:42 клиент: текст». */
    private static function lines(Collection $messages, string $tz): array
    {
        return $messages->map(function ($m) use ($tz) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) ($m->body ?: $m->preview())));

            return $m->sent_at->timezone($tz)->format('H:i')
                . ($m->from_me ? ' клуб: ' : ' клиент: ')
                . mb_substr($text, 0, self::MAX_LINE);
        })->all();
    }

    /**
     * Кто из написавших в этот день дошёл до брони.
     * Номера в бронях записаны как придётся — сверяем по последним 10 цифрам.
     *
     * @return array<int, string> последние 10 цифр номеров
     */
    private static function bookedPhones(int $clubId, array $phones, CarbonInterface $start, CarbonInterface $end): array
    {
        $tails = collect($phones)->map(fn ($p) => substr($p, -10))->filter()->unique();
        if ($tails->isEmpty()) {
            return [];
        }

        $found = [];
        // Клуб у брони — через корт: своей колонки club_id тут нет.
        CourtBooking::whereHas('court', fn ($q) => $q->where('club_id', $clubId))
            ->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->utc()])
            ->whereNotNull('client_phone')
            ->select(['id', 'client_phone'])
            ->chunk(500, function ($bookings) use (&$found, $tails) {
                foreach ($bookings as $booking) {
                    $tail = substr(preg_replace('/\D/', '', (string) $booking->client_phone), -10);
                    if (strlen($tail) === 10 && $tails->contains($tail)) {
                        $found[$tail] = $tail;
                    }
                }
            });

        return array_values($found);
    }
}
