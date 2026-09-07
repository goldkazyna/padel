<?php

namespace App\Support;

/**
 * Разбор JSON, пришедшего от языковой модели.
 *
 * Модель иногда оборачивает ответ в ```json ... ```, добавляет пояснение
 * перед объектом или — реже — путает закрывающую скобку: `"worst_match": {
 * ... ]`. Первые два случая лечатся здесь; последний — не лечится и не
 * должен: чинить сломанную структуру угадыванием значит однажды показать
 * человеку чужой текст под видом его разбора.
 */
class AiJson
{
    /**
     * Вернуть массив или null, если ответ не разобрался.
     */
    public static function decode(string $text): ?array
    {
        $json = trim($text);

        // Ограждения ```json ... ```
        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```(?:json)?\s*/i', '', $json);
            $json = preg_replace('/\s*```$/i', '', $json);
            $json = trim($json);
        }

        // Пояснение до или после объекта: берём от первой { до последней }.
        $start = strpos($json, '{');
        $end = strrpos($json, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $json = substr($json, $start, $end - $start + 1);
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}
