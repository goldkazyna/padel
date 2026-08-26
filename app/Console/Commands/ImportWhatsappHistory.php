<?php

namespace App\Console\Commands;

use App\Services\WhapiService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Выгрузка переписки из Whapi в нашу базу.
 *
 * Вебхук приносит только то, что пришло после подключения, а история за
 * прошлые месяцы живёт на стороне Whapi. Команда идёт страницами от свежих
 * к старым и останавливается, дойдя до нужной глубины.
 */
class ImportWhatsappHistory extends Command
{
    protected $signature = 'whatsapp:import
        {--days=30 : На сколько дней назад выгружать}
        {--all : Выгрузить всю доступную историю}
        {--page-size=500 : Сообщений за один запрос}';

    protected $description = 'Выгрузить историю WhatsApp из Whapi.Cloud';

    public function handle(WhapiService $whapi): int
    {
        $pageSize = (int) $this->option('page-size');
        $until = $this->option('all')
            ? null
            : now()->subDays((int) $this->option('days'));

        $this->info($until
            ? 'Выгружаю сообщения с ' . $until->toDateString()
            : 'Выгружаю всю историю');

        $offset = 0;
        $saved = 0;
        $seen = 0;

        while (true) {
            $page = $whapi->fetchHistory($pageSize, $offset);
            $messages = $page['messages'] ?? [];

            if (!$messages) {
                break;
            }

            $seen += count($messages);
            $saved += $whapi->storeWebhook([
                'channel_id' => config('services.whapi.channel'),
                'messages' => $messages,
            ]);

            $oldest = min(array_map(fn ($m) => (int) ($m['timestamp'] ?? 0), $messages));
            $this->line(sprintf(
                '  %6d просмотрено, %6d новых, дошли до %s',
                $seen,
                $saved,
                Carbon::createFromTimestamp($oldest)->timezone(config('app.schedule_timezone', 'Asia/Almaty'))->format('d.m.Y H:i')
            ));

            if ($until && $oldest && Carbon::createFromTimestamp($oldest)->lessThan($until)) {
                break;
            }
            if (count($messages) < $pageSize) {
                break;
            }

            $offset += $pageSize;
        }

        $this->info("Готово: просмотрено {$seen}, добавлено новых {$saved}.");

        return self::SUCCESS;
    }
}
