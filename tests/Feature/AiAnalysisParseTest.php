<?php

namespace Tests\Feature;

use App\Support\AiJson;
use Tests\TestCase;

/**
 * Разбор ответа модели.
 *
 * На проде разбор второго этапа лиги вышел на экран сырым JSON: модель
 * закрыла объект «worst_match» квадратной скобкой вместо фигурной, разбор
 * упал, а фолбэк показал текст как есть. Такой ответ читаться и не должен —
 * но и на экран он попадать не может.
 */
class AiAnalysisParseTest extends TestCase
{
    public function test_чистый_json_читается(): void
    {
        $data = AiJson::decode('{"headline":"Хорошо","tips":["раз"]}');

        $this->assertSame('Хорошо', $data['headline']);
        $this->assertSame(['раз'], $data['tips']);
    }

    public function test_ограждения_снимаются(): void
    {
        $text = "```json\n{\"headline\":\"Хорошо\"}\n```";

        $this->assertSame('Хорошо', AiJson::decode($text)['headline']);
    }

    public function test_пояснение_до_и_после_объекта_отбрасывается(): void
    {
        $text = "Вот разбор:\n{\"headline\":\"Хорошо\"}\nНадеюсь, помог.";

        $this->assertSame('Хорошо', AiJson::decode($text)['headline']);
    }

    public function test_сломанная_скобка_это_null_а_не_мусор(): void
    {
        // Ровно тот случай, что вышел на экран: объект закрыт «]».
        $broken = <<<'JSON'
{
  "headline": "Солидный результат",
  "worst_match": {
    "label": "sokolov dmitrii и Ольга Стюхина (11:13)",
    "detail": "Штраф -30 за неожиданное поражение"
  ],
  "tips": ["Держите концентрацию"]
}
JSON;

        $this->assertNull(AiJson::decode($broken));
    }

    public function test_обрыв_на_полуслове_тоже_null(): void
    {
        $cut = '{"headline":"Солидный результат","summary":"Вы выиграли 5 матчей из';

        $this->assertNull(AiJson::decode($cut));
    }
}
