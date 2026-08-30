<?php

namespace App\Services;

use App\Models\WhatsappAnalysis;
use App\Support\WhatsappDayReport;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Разбор дня переписки WhatsApp через Claude API.
 *
 * Цифры (время ответа, кто без ответа, кто дошёл до брони) считает
 * WhatsappDayReport и передаёт как факт. Модель читает сами диалоги и
 * отвечает на то, что арифметикой не берётся: где упустили продажу, где
 * ответили формально, что переделать завтра.
 */
class WhatsappAnalysisService
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** Диалоги режем, чтобы уложиться в разумный запрос. */
    private const MAX_CHARS = 120000;

    /**
     * Разобрать день. Готовый разбор возвращается из базы, пока не попросили
     * пересобрать: каждый вызов модели стоит денег и времени.
     */
    public function analyze(int $clubId, CarbonInterface $day, bool $force = false, ?int $userId = null): WhatsappAnalysis
    {
        $date = $day->copy()->timezone(config('app.schedule_timezone', 'Asia/Almaty'))->toDateString();

        // Ищем по whereDate: колонка приводится к дате, а строка сравнения
        // в разных драйверах хранится по-разному — точное равенство промахивается.
        $existing = WhatsappAnalysis::where('club_id', $clubId)->whereDate('date', $date)->first();
        if ($existing && !$force) {
            return $existing;
        }

        $report = WhatsappDayReport::build($clubId, $day);

        if (($report['metrics']['dialogs'] ?? 0) === 0) {
            throw new RuntimeException('За этот день переписки нет — разбирать нечего.');
        }

        $answer = $this->ask($report);

        $fields = [
            'metrics' => $report['metrics'],
            'report' => $answer['report'],
            'model' => $answer['model'],
            'generated_by' => $userId,
            'generated_at' => now(),
        ];

        if ($existing) {
            $existing->update($fields);

            return $existing;
        }

        return WhatsappAnalysis::create($fields + ['club_id' => $clubId, 'date' => $date]);
    }

    /** @return array{model:string, report:array} */
    private function ask(array $report): array
    {
        $key = config('services.anthropic.key');
        if (empty($key)) {
            throw new RuntimeException('ANTHROPIC_API_KEY не задан');
        }

        $model = config('services.whapi.analysis_model')
            ?: config('services.anthropic.model', 'claude-haiku-4-5-20251001');

        $response = Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => config('services.anthropic.version', '2023-06-01'),
            'content-type' => 'application/json',
        ])->connectTimeout(15)->timeout(180)->post(self::ENDPOINT, [
            'model' => $model,
            'max_tokens' => 8000,
            // Размышление выключено намеренно: у Sonnet оно включено по
            // умолчанию и на разборе дня съедало весь лимит токенов —
            // ответ приходил без единого текстового блока («Claude вернул
            // пустой ответ»). Без него разбор ещё и втрое дешевле.
            'thinking' => ['type' => 'disabled'],
            'system' => $this->systemPrompt($report['metrics']),
            'messages' => [[
                'role' => 'user',
                'content' => $this->userPrompt($report),
            ]],
        ]);

        if (!$response->successful()) {
            Log::warning('Claude: разбор дня WhatsApp не удался', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);

            throw new RuntimeException('Claude ответил ошибкой ' . $response->status());
        }

        // Ответ приходит блоками, и первым может лежать не текст
        // (например, рассуждение) — собираем все текстовые.
        $text = collect($response->json('content') ?? [])
            ->filter(fn ($block) => ($block['type'] ?? '') === 'text')
            ->pluck('text')
            ->filter(fn ($t) => is_string($t) && $t !== '')
            ->implode('');

        if ($text === '') {
            $stop = (string) $response->json('stop_reason');

            Log::warning('Claude: в ответе нет текстовых блоков', [
                'stop_reason' => $stop,
                'usage' => $response->json('usage'),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);

            throw new RuntimeException($stop === 'max_tokens'
                ? 'Модель не уложилась в лимит ответа — попробуйте ещё раз'
                : 'Claude вернул пустой ответ');
        }

        return ['model' => $model, 'report' => $this->parse($text)];
    }

    private function systemPrompt(array $metrics): string
    {
        $threshold = $metrics['threshold'] ?? 5;
        $hours = $metrics['work_hours'] ?? '09:00-23:00';

        return <<<PROMPT
Ты — требовательный руководитель отдела продаж падел-клуба. Тебе дают переписку клуба в WhatsApp за один день и точные цифры по ней.

Твоя задача — жёсткий, конкретный разбор без вежливой воды. Не хвали за то, что «в целом неплохо». Клуб теряет деньги на каждом непроданном корте, и твоя работа — показать где именно.

Правила:
- Цифры в metrics — факт. Не пересчитывай их и не спорь с ними.
- Ответ дольше {$threshold} минут в рабочее время ({$hours}) — плохо. Отсутствие ответа — грубая ошибка.
- Ночное время не считается просрочкой: клуб закрыт.
- Ссылайся на конкретные диалоги: последние 4 цифры номера и цитата из переписки. Без цитат разбор бесполезен.
- Не выдумывай диалогов, имён и сумм, которых нет в данных.
- Отличай реальную потерю от вежливого «спасибо»: если клиент попрощался, ответ не требуется, и это не нарушение.
- Без ответа остались ТОЛЬКО реплики, помеченные «← ОСТАЛОСЬ БЕЗ ОТВЕТА» и перечисленные в шапке диалога. Если после сообщения клиента идёт строка «клуб:» — на него ответили, и называть его «висело без ответа» нельзя.
- Пиши по-русски, коротко, деловым языком. Никакого маркдауна внутри строк.

Ответь СТРОГО одним JSON-объектом по схеме:
{
  "verdict": "один абзац: что за день это был и главная проблема",
  "lost_sales": [ {"phone": "последние 4 цифры", "what": "что просил клиент", "why": "почему сделка не состоялась", "quote": "цитата из переписки"} ],
  "slow": [ {"phone": "последние 4 цифры", "waited": "сколько ждал", "what": "о чём спрашивал"} ],
  "quality": [ {"issue": "что не так в общении", "example": "цитата", "fix": "как надо"} ],
  "good": [ "что сделано хорошо, 0-2 пункта, только если правда есть" ],
  "actions": [ "что изменить завтра, 3-5 пунктов, каждый — конкретное действие" ],
  "automation": [ {"question": "тип вопроса клиента своими словами", "times": "сколько раз встретился за день (числом)", "answer": "готовый текст автоответа, который клуб может слать без человека", "caution": "когда так отвечать нельзя и нужен менеджер"} ]
}
Правила по спискам: lost_sales — до 8 пунктов, самые дорогие потери первыми; slow — до 8; quality — до 5; automation — до 6.

Про automation отдельно. Клуб хочет постепенно отвечать на часть сообщений автоматически, и твоя задача — показать, что уже сегодня можно отдать роботу без риска.
- Бери только повторяющиеся вопросы с однозначным ответом: цена, адрес и как проехать, часы работы, есть ли прокат ракеток, как оплатить, есть ли раздевалки и душ.
- Текст в answer пиши как готовое сообщение клиенту, от лица клуба, без плейсхолдеров вроде «укажите цену»: если точных данных в переписке нет, возьми формулировку из ответов менеджеров этого же дня.
- В caution честно пиши, где автоответ навредит: подбор свободного времени, переносы, споры о деньгах, недовольство, индивидуальные скидки — это к человеку.
- Если день не дал повторяющихся вопросов, ставь пустой массив, не выдумывай.
Если чего-то нет, ставь пустой массив.
PROMPT;
    }

    private function userPrompt(array $report): string
    {
        $lines = [];
        $lines[] = 'Дата: ' . $report['date'];
        $lines[] = 'Цифры (посчитаны системой, это факт):';
        $lines[] = json_encode($report['metrics'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $lines[] = '';
        $lines[] = 'Диалоги за день:';

        $used = 0;
        foreach ($report['dialogs'] as $dialog) {
            $head = sprintf(
                "\n--- %s%s%s%s ---",
                $dialog['hidden_number'] ? 'номер скрыт' : '…' . substr($dialog['phone'], -4),
                $dialog['is_new'] ? ', первое обращение' : '',
                // Раньше писали просто «остался без ответа», и модель
                // выбирала не ту реплику. Теперь называем её прямо.
                $dialog['unanswered']
                    ? ', без ответа: ' . implode('; ', $dialog['unanswered_lines'] ?? [])
                    : '',
                $dialog['booked'] ? ', в этот день оформлена бронь' : ', брони в этот день нет'
            );

            $block = $head . "\n" . implode("\n", $dialog['lines']);
            if ($used + mb_strlen($block) > self::MAX_CHARS) {
                $lines[] = "\n(остальные диалоги не поместились)";
                break;
            }

            $used += mb_strlen($block);
            $lines[] = $block;
        }

        return implode("\n", $lines);
    }

    /** Достаём JSON из ответа модели и приводим к ожидаемой схеме. */
    private function parse(string $text): array
    {
        $json = trim($text);
        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $json);
        }

        $start = strpos($json, '{');
        $end = strrpos($json, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $json = substr($json, $start, $end - $start + 1);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            // Не разобрали — показываем текстом, чтобы разбор не пропал.
            return [
                'verdict' => trim($text),
                'lost_sales' => [],
                'slow' => [],
                'quality' => [],
                'good' => [],
                'actions' => [],
                'automation' => [],
            ];
        }

        $rows = function ($items, array $keys) {
            $out = [];
            foreach ((array) $items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $row = [];
                foreach ($keys as $k) {
                    $row[$k] = trim((string) ($item[$k] ?? ''));
                }
                if (implode('', $row) !== '') {
                    $out[] = $row;
                }
            }

            return $out;
        };

        $strings = fn ($items) => array_values(array_filter(array_map(
            fn ($i) => is_string($i) ? trim($i) : '',
            (array) $items
        )));

        return [
            'verdict' => trim((string) ($data['verdict'] ?? '')),
            'lost_sales' => $rows($data['lost_sales'] ?? [], ['phone', 'what', 'why', 'quote']),
            'slow' => $rows($data['slow'] ?? [], ['phone', 'waited', 'what']),
            'quality' => $rows($data['quality'] ?? [], ['issue', 'example', 'fix']),
            'good' => $strings($data['good'] ?? []),
            'actions' => $strings($data['actions'] ?? []),
            'automation' => $rows($data['automation'] ?? [], ['question', 'times', 'answer', 'caution']),
        ];
    }
}
