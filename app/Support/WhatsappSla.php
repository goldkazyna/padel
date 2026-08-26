<?php

namespace App\Support;

use App\Models\WhatsappMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Сколько клиент ждёт ответа в WhatsApp.
 *
 * Считаем только рабочие часы клуба: сообщение в 23:40 с ответом в 09:05
 * — это не «девять часов молчали», а «ответили на открытии». Иначе отчёт
 * превращается в обвинение ночной смены, которой не существует.
 */
class WhatsappSla
{
    public static function timezone(): string
    {
        return (string) config('app.schedule_timezone', 'Asia/Almaty');
    }

    public static function workFrom(): string
    {
        return (string) config('services.whapi.work_from', '09:00');
    }

    public static function workTo(): string
    {
        return (string) config('services.whapi.work_to', '23:00');
    }

    /** Через сколько рабочих минут ожидание считается просроченным. */
    public static function threshold(): int
    {
        return (int) config('services.whapi.sla_minutes', 15);
    }

    /**
     * Рабочие минуты между двумя моментами.
     *
     * Идём по дням: у каждого берём пересечение с рабочим окном клуба.
     * Ночь и время до открытия в счёт не идут.
     */
    public static function businessMinutes(CarbonInterface $from, CarbonInterface $to): int
    {
        $tz = self::timezone();
        $start = $from->copy()->timezone($tz);
        $end = $to->copy()->timezone($tz);

        if ($end <= $start) {
            return 0;
        }

        $minutes = 0;
        $day = $start->copy()->startOfDay();

        while ($day < $end) {
            $open = $day->copy()->setTimeFromTimeString(self::workFrom());
            $close = $day->copy()->setTimeFromTimeString(self::workTo());

            $windowStart = $start->greaterThan($open) ? $start : $open;
            $windowEnd = $end->lessThan($close) ? $end : $close;

            if ($windowEnd->greaterThan($windowStart)) {
                $minutes += $windowStart->diffInMinutes($windowEnd);
            }

            $day = $day->copy()->addDay()->startOfDay();
        }

        return $minutes;
    }

    /**
     * Диалоги, где последнее слово осталось за клиентом.
     *
     * Ожидание отсчитываем от первого сообщения непрерывной серии клиента,
     * а не от последнего: три сообщения подряд — это одно ожидание, и
     * началось оно с первого.
     *
     * @return Collection<int, array>
     */
    public static function waitingChats(int $clubId, int $days = 90): Collection
    {
        $messages = WhatsappMessage::where('club_id', $clubId)
            ->where('sent_at', '>=', now()->subDays($days))
            // Групповые чаты — не обращения: там переписываются игроки между
            // собой, и «ответить» клубу там некому.
            ->where('chat_id', 'not like', '%@g.us')
            ->where('phone', '<>', '')
            // action — служебные события WhatsApp (добавили в группу, удалили
            // сообщение), их клиент не писал.
            ->whereNotIn('type', ['action', 'system', 'notification'])
            ->orderBy('sent_at')
            ->get(['id', 'phone', 'chat_id', 'from_me', 'sent_at', 'body', 'type', 'author_name']);

        return $messages
            ->groupBy('phone')
            ->map(function (Collection $chat, string $phone) {
                $last = $chat->last();
                if ($last->from_me) {
                    return null;                    // ответили — ждать нечего
                }

                // Отматываем назад до первого сообщения серии клиента.
                $streak = [];
                foreach ($chat->reverse() as $message) {
                    if ($message->from_me) {
                        break;
                    }
                    $streak[] = $message;
                }
                $first = end($streak);

                $waitedMinutes = self::businessMinutes($first->sent_at, now());

                return [
                    'phone' => $phone,
                    // У части клиентов WhatsApp отдаёт LID вместо номера —
                    // это живой человек, но телефон скрыт настройками.
                    'hidden_number' => !str_contains((string) $last->chat_id, '@s.whatsapp.net'),
                    'name' => $chat->firstWhere('from_me', false)?->author_name,
                    'since' => $first->sent_at,
                    'last' => $last,
                    'messages' => count($streak),
                    'waited' => $waitedMinutes,
                    'overdue' => $waitedMinutes > self::threshold(),
                    // Отвечали ли вообще когда-нибудь: новый клиент, которому
                    // не ответили ни разу, — потеря куда обиднее.
                    'ever_answered' => $chat->contains('from_me', true),
                ];
            })
            ->filter()
            ->sortByDesc('waited')
            ->values();
    }

    /** «1 ч 20 мин» — минуты в человеческом виде. */
    public static function humanMinutes(int $minutes): string
    {
        if ($minutes < 1) {
            return 'меньше минуты';
        }
        if ($minutes < 60) {
            return $minutes . ' мин';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours < 24) {
            return $rest ? "{$hours} ч {$rest} мин" : "{$hours} ч";
        }

        $days = intdiv($hours, 24);
        $restHours = $hours % 24;

        return $restHours ? "{$days} дн {$restHours} ч" : "{$days} дн";
    }
}
