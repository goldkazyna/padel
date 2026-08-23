<?php

namespace App\Support;

/**
 * Поиск людей по имени, не разбираясь, на каком алфавите оно записано.
 *
 * Половина игроков заводит себя кириллицей («Денис»), половина латиницей
 * («Denis»), а ищут всегда так, как привыкли — и половину людей не находят.
 * Класс подбирает к запросу правдоподобные написания на другом алфавите и
 * ищет по всем сразу. Точные совпадения при этом остаются наверху списка:
 * искал «Денис» — сначала Денисы, потом Denis'ы.
 *
 * Однозначной транслитерации не существует: «Жанна» пишут и Zhanna, и Janna,
 * «Хасан» — Khasan и Hasan. Поэтому у неоднозначных букв держим по два
 * варианта и перебираем сочетания, ограничивая их число: запрос из пяти
 * неоднозначных букв иначе дал бы 32 варианта LIKE в одном запросе.
 */
class NameSearch
{
    /** Сколько написаний максимум подставляем в запрос. */
    private const MAX_VARIANTS = 12;

    /** Кириллица → латиница. Массив = равноправные написания. */
    private const TO_LATIN = [
        'а' => 'a',  'б' => 'b',  'в' => 'v',  'г' => 'g',  'д' => 'd',
        'е' => 'e', 'ё' => ['e', 'yo'], 'ж' => ['zh', 'j'],
        'з' => 'z',  'и' => 'i',  'й' => ['y', 'i'], 'к' => 'k',
        'л' => 'l',  'м' => 'm',  'н' => 'n',  'о' => 'o',  'п' => 'p',
        'р' => 'r',  'с' => 's',  'т' => 't',  'у' => 'u',  'ф' => 'f',
        'х' => ['kh', 'h'], 'ц' => ['ts', 'c'], 'ч' => 'ch', 'ш' => 'sh',
        'щ' => ['shch', 'sch'], 'ъ' => '', 'ы' => ['y', 'i'], 'ь' => '',
        'э' => 'e',  'ю' => ['yu', 'iu'], 'я' => ['ya', 'ia'],
        // Казахские буквы.
        'ә' => ['a', 'ae'], 'ғ' => ['g', 'gh'], 'қ' => ['q', 'k'],
        'ң' => ['n', 'ng'], 'ө' => 'o', 'ұ' => 'u', 'ү' => 'u',
        'һ' => 'h', 'і' => 'i',
    ];

    /**
     * Буквы, которые в НАЧАЛЕ слова пишут иначе: Ержан — Yerzhan, но Денис —
     * Denis, а не Dyenis. Без этого разделения запрос обрастает вариантами,
     * которые заведомо ничему не соответствуют.
     */
    private const TO_LATIN_WORD_START = [
        'е' => ['ye', 'e'], 'ё' => ['yo', 'e'],
    ];

    /** Латиница → кириллица. Сначала сочетания букв, потом одиночные. */
    private const TO_CYRILLIC_DIGRAPHS = [
        'shch' => 'щ', 'sch' => 'щ', 'zh' => 'ж', 'kh' => 'х', 'ch' => 'ч',
        'sh' => 'ш', 'ya' => 'я', 'yu' => 'ю', 'ye' => 'е', 'yo' => 'ё',
        // Спорные сочетания: «Диана» пишут Diana, а не Дяна, «Чингиз» —
        // Chingiz, а не Чиниз. Держим оба прочтения, основное первым.
        'ts' => ['ц', 'тс'], 'ia' => ['иа', 'я'], 'iu' => ['иу', 'ю'],
        'ng' => ['нг', 'ң'], 'gh' => ['г', 'ғ'],
    ];

    private const TO_CYRILLIC = [
        'a' => 'а', 'b' => 'б', 'c' => ['к', 'ц'], 'd' => 'д', 'e' => 'е',
        'f' => 'ф', 'g' => 'г', 'h' => 'х', 'i' => 'и', 'j' => ['ж', 'дж'],
        'k' => 'к', 'l' => 'л', 'm' => 'м', 'n' => 'н', 'o' => 'о',
        'p' => 'п', 'q' => ['к', 'қ'], 'r' => 'р', 's' => 'с', 't' => 'т',
        'u' => 'у', 'v' => 'в', 'w' => 'в', 'x' => 'кс', 'y' => ['ы', 'й'],
        'z' => 'з',
    ];

    /**
     * Написания запроса, по которым имеет смысл искать: сам запрос первым,
     * дальше варианты на другом алфавите.
     */
    public static function variants(string $query): array
    {
        $query = trim($query);
        if ($query === '') return [];

        $variants = [$query];
        $lower = mb_strtolower($query);

        if (self::hasCyrillic($lower)) {
            $variants = array_merge($variants, self::toLatin($lower));
        }
        if (self::hasLatin($lower)) {
            $variants = array_merge($variants, self::toCyrillic($lower));
        }

        // Пустые и повторы (например, когда транслитерация вернула исходное).
        $seen = [];
        $result = [];
        foreach ($variants as $v) {
            $key = mb_strtolower($v);
            if ($v === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $result[] = $v;
        }

        return array_slice($result, 0, self::MAX_VARIANTS);
    }

    /**
     * Добавить к запросу поиск по всем написаниям.
     *
     * $columns — колонки с именем; ищем по каждой, потому что имя хранится и
     * целиком в `name`, и по частям в `first_name` / `last_name`.
     */
    public static function apply($query, ?string $search, array $columns = ['name', 'first_name', 'last_name'])
    {
        $variants = self::variants((string) $search);
        if (empty($variants)) return $query;

        return $query->where(function ($q) use ($variants, $columns) {
            foreach ($variants as $variant) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', '%' . $variant . '%');
                }
            }
        });
    }

    /**
     * Поднять наверх тех, кто совпал с запросом как есть: искал кириллицей —
     * сначала кириллица, латинские написания ниже.
     *
     * Вызывать ПЕРЕД остальными orderBy, иначе приоритет ни на что не влияет.
     */
    public static function orderExactFirst($query, ?string $search, array $columns = ['name', 'first_name', 'last_name'])
    {
        $search = trim((string) $search);
        if ($search === '' || count(self::variants($search)) < 2) return $query;

        $conditions = [];
        $bindings = [];
        foreach ($columns as $column) {
            $conditions[] = "`{$column}` LIKE ?";
            $bindings[] = '%' . $search . '%';
        }

        return $query->orderByRaw(
            'CASE WHEN ' . implode(' OR ', $conditions) . ' THEN 0 ELSE 1 END',
            $bindings
        );
    }

    private static function hasCyrillic(string $s): bool
    {
        return (bool) preg_match('/[\x{0400}-\x{04FF}]/u', $s);
    }

    private static function hasLatin(string $s): bool
    {
        return (bool) preg_match('/[a-z]/u', $s);
    }

    /** Все латинские написания кириллического запроса. */
    private static function toLatin(string $s): array
    {
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        $options = [];
        $wordStart = true;

        foreach ($chars as $ch) {
            $map = ($wordStart ? (self::TO_LATIN_WORD_START[$ch] ?? null) : null)
                ?? self::TO_LATIN[$ch] ?? null;

            // Пробелы и дефисы начинают новое слово; сами по себе
            // не транслитерируются — как и латиница в смешанном имени.
            $wordStart = $ch === ' ' || $ch === '-';

            if ($map === null) {
                $options[] = [$ch];
                continue;
            }
            $options[] = is_array($map) ? $map : [$map];
        }

        return self::combine($options);
    }

    /** Все кириллические написания латинского запроса. */
    private static function toCyrillic(string $s): array
    {
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        $count = count($chars);
        $options = [];
        $i = 0;

        while ($i < $count) {
            // Сначала пробуем сочетания букв — «sh» это ш, а не с+х.
            $matched = false;
            foreach ([4, 3, 2] as $size) {
                if ($i + $size > $count) continue;
                $chunk = implode('', array_slice($chars, $i, $size));
                if (isset(self::TO_CYRILLIC_DIGRAPHS[$chunk])) {
                    $options[] = (array) self::TO_CYRILLIC_DIGRAPHS[$chunk];
                    $i += $size;
                    $matched = true;
                    break;
                }
            }
            if ($matched) continue;

            $ch = $chars[$i];
            $map = self::TO_CYRILLIC[$ch] ?? null;
            $options[] = $map === null ? [$ch] : (is_array($map) ? $map : [$map]);
            $i++;
        }

        return self::combine($options);
    }

    /**
     * Собрать написания из вариантов по каждой букве.
     * Растём вширь, но не дальше MAX_VARIANTS — иначе длинное имя с пятью
     * неоднозначными буквами даст десятки LIKE в одном запросе.
     */
    private static function combine(array $options): array
    {
        $results = [''];
        foreach ($options as $choices) {
            // Если ветвление раздуло бы список — у этой буквы берём только
            // основное написание. Букву при этом не теряем.
            if (count($results) * count($choices) > self::MAX_VARIANTS) {
                $choices = [reset($choices)];
            }

            $next = [];
            foreach ($results as $prefix) {
                foreach ($choices as $choice) {
                    $next[] = $prefix . $choice;
                }
            }
            $results = $next;
        }

        return $results;
    }
}
