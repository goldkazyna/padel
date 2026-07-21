<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;

class HelpController extends Controller
{
    /**
     * Структура раздела «Помощь»: категории и статьи.
     * Статья со slug рендерится из resources/views/club/help/articles/{slug}.blade.php
     * (пустой массив articles = категория пока без статей, «скоро»).
     */
    public static function sections(): array
    {
        return [
            [
                'title' => 'Турниры',
                'icon' => 'bi-trophy',
                'articles' => [
                    ['slug' => 'create-tournament', 'title' => 'Как создать турнир', 'excerpt' => 'Пошагово: от выбора типа до публикации и старта.'],
                    ['slug' => 'conduct-americano', 'title' => 'Как проводить Американо', 'excerpt' => 'Участники, старт, ввод счёта, таблица, завершение.'],
                    ['slug' => 'conduct-team', 'title' => 'Как проводить командный турнир', 'excerpt' => 'Пары, группы, круговой этап, плей-офф, победитель.'],
                    ['slug' => 'moderate-participants', 'title' => 'Модерация и добавление участников', 'excerpt' => 'Заявки, приглашения, ручное добавление игроков.'],
                ],
            ],
            [
                'title' => 'Группы и занятия',
                'icon' => 'bi-people',
                'articles' => [],
            ],
            [
                'title' => 'Клиенты и карты',
                'icon' => 'bi-person-vcard',
                'articles' => [],
            ],
            [
                'title' => 'Расписание и брони',
                'icon' => 'bi-calendar3',
                'articles' => [],
            ],
        ];
    }

    /** Найти статью и её категорию по slug. */
    protected static function findArticle(string $slug): ?array
    {
        foreach (self::sections() as $section) {
            foreach ($section['articles'] as $article) {
                if ($article['slug'] === $slug) {
                    return ['article' => $article, 'section' => $section];
                }
            }
        }
        return null;
    }

    public function index()
    {
        return view('club.help.index', ['sections' => self::sections()]);
    }

    public function show(string $slug)
    {
        $found = self::findArticle($slug);
        $view = 'club.help.articles.' . $slug;
        abort_if($found === null || !view()->exists($view), 404);

        return view('club.help.show', [
            'article'     => $found['article'],
            'section'     => $found['section'],
            'articleView' => $view,
        ]);
    }
}
