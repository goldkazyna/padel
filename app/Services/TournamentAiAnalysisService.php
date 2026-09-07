<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Генерация AI-разбора выступления игрока в турнире через Claude API (Anthropic).
 * Данные (счёт матчей, соперники, дельты рейтинга) собираются вызывающим кодом
 * из тех же источников, что и экран результатов — модель их только объясняет,
 * ничего не пересчитывает.
 */
class TournamentAiAnalysisService
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const LANG_NAMES = [
        'ru' => 'Russian',
        'en' => 'English',
        'kk' => 'Kazakh',
    ];

    /**
     * @param  array  $context  Данные выступления (tournament, player, matches, field).
     * @param  string $lang     Язык ответа: ru|en|kk.
     * @return array{model:string, analysis:array}
     *
     * @throws RuntimeException при отсутствии ключа или ошибке API.
     */
    public function generate(array $context, string $lang = 'ru'): array
    {
        $key = config('services.anthropic.key');
        if (empty($key)) {
            throw new RuntimeException('ANTHROPIC_API_KEY не задан');
        }

        $model = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
        $langName = self::LANG_NAMES[$lang] ?? 'Russian';

        // Модель иногда путает закрывающую скобку — тогда JSON не читается.
        // Второй заход с явным напоминанием обычно спасает; если и он не
        // помог, лучше честная ошибка, чем сырой JSON на экране.
        $analysis = $this->ask($key, $model, $langName, $context, false);
        if ($analysis === null) {
            $analysis = $this->ask($key, $model, $langName, $context, true);
        }

        if ($analysis === null) {
            throw new RuntimeException('Модель вернула неразбираемый ответ');
        }

        return [
            'model' => $model,
            'analysis' => $analysis,
        ];
    }

    /**
     * Один запрос к модели. Возвращает разобранный разбор или null, если
     * ответ не читается как JSON.
     *
     * @param  array $context
     * @return array<string, mixed>|null
     */
    private function ask(
        string $key,
        string $model,
        string $langName,
        array $context,
        bool $strict
    ): ?array {
        $user = "Данные выступления игрока (JSON):\n\n"
            . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($strict) {
            $user .= "\n\nПрошлый ответ не разобрался. Верни строго валидный JSON "
                . "по схеме, проверь парность скобок, ничего не пиши до и после него.";
        }

        $response = Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => config('services.anthropic.version', '2023-06-01'),
            'content-type' => 'application/json',
        ])->connectTimeout(15)->timeout(60)->post(self::ENDPOINT, [
            'model' => $model,
            // С запасом: на 1400 длинный разбор обрывался на полуслове.
            'max_tokens' => 2000,
            'system' => $this->systemPrompt($langName),
            'messages' => [
                ['role' => 'user', 'content' => $user],
                // Подсказываем начало ответа: так модель не оборачивает его
                // в ```json и не пишет вступление.
                ['role' => 'assistant', 'content' => '{'],
            ],
        ]);

        if (!$response->successful()) {
            Log::warning('Anthropic API error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException('Ошибка Claude API: ' . $response->status());
        }

        $text = $response->json('content.0.text');
        if (!is_string($text) || $text === '') {
            throw new RuntimeException('Пустой ответ Claude API');
        }

        // Ответ продолжает нашу открывающую скобку.
        $data = \App\Support\AiJson::decode('{' . $text);
        if ($data === null) {
            Log::warning('AI analysis: ответ не разобрался', [
                'strict' => $strict,
                'head' => mb_substr($text, 0, 200),
            ]);

            return null;
        }

        return $this->normalize($data);
    }

    private function systemPrompt(string $langName): string
    {
        return <<<PROMPT
Ты — дружелюбный, но конкретный тренер по паделу. Тебе дают итоги выступления игрока в турнире: его матчи (счёт, соперники с рейтингами), изменение рейтинга за каждый матч и суммарно, место. Твоя задача — понятно объяснить игроку, ПОЧЕМУ он получил именно столько рейтинга, и дать пару советов.

Как считается рейтинг (Elo), учитывай это в объяснении:
- Дельта за матч зависит от разницы рейтингов соперников. Обыграть более сильных = много очков; проиграть более сильным = потерять мало. Обыграть слабых = мало очков; проиграть слабым = потерять много.
- Крупный счёт (разгром) увеличивает дельту, близкий — уменьшает.
- Победа всегда даёт минимум +1.
- Матч со счётом 0:0 не влияет на рейтинг (считается несыгранным).
- Рейтинг применяется один раз по итогам турнира.

Опирайся ТОЛЬКО на переданные числа (rating_change по матчам и суммарно — это факт, не пересчитывай). Не выдумывай матчей и соперников. Будь краток и по делу.

Ответь СТРОГО одним JSON-объектом (без markdown, без пояснений вокруг) на языке: {$langName}. Схема:
{
  "headline": "короткий вердикт одной фразой",
  "summary": "2-3 предложения: общий итог и главная причина такой дельты рейтинга",
  "factors": [ {"title": "фактор", "detail": "почему это повлияло на рейтинг"} ],
  "best_match": {"label": "соперник и счёт", "detail": "чем матч был ценен по очкам"},
  "worst_match": {"label": "соперник и счёт", "detail": "что забрало очки"},
  "tips": ["1-2 практичных совета, как расти в рейтинге"]
}
Правила: factors — 2-4 пункта. Если подходящего лучшего/худшего матча нет (например, все матчи равнозначны или матчей мало), ставь null. tips — 1-2 пункта. Не используй markdown внутри строк.
PROMPT;
    }

    /**
     * Привести разобранный ответ к ожидаемой схеме.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $normMatch = function ($m) {
            if (!is_array($m)) return null;
            $label = trim((string) ($m['label'] ?? ''));
            $detail = trim((string) ($m['detail'] ?? ''));
            if ($label === '' && $detail === '') return null;
            return ['label' => $label, 'detail' => $detail];
        };

        $factors = [];
        foreach ((array) ($data['factors'] ?? []) as $f) {
            if (!is_array($f)) continue;
            $title = trim((string) ($f['title'] ?? ''));
            $detail = trim((string) ($f['detail'] ?? ''));
            if ($title === '' && $detail === '') continue;
            $factors[] = ['title' => $title, 'detail' => $detail];
        }

        $tips = [];
        foreach ((array) ($data['tips'] ?? []) as $t) {
            $t = trim((string) $t);
            if ($t !== '') $tips[] = $t;
        }

        return [
            'headline' => trim((string) ($data['headline'] ?? '')),
            'summary' => trim((string) ($data['summary'] ?? '')),
            'factors' => $factors,
            'best_match' => $normMatch($data['best_match'] ?? null),
            'worst_match' => $normMatch($data['worst_match'] ?? null),
            'tips' => $tips,
        ];
    }
}
