<?php

namespace App\Achievements;

/**
 * Правило одного значка.
 *
 * Правила живут классами, а не строками в базе: «пять побед подряд» или
 * «все матчи турнира выиграны» — это логика, а не число в колонке. Хранить
 * её в базе значило бы писать интерпретатор.
 */
interface Achievement
{
    public function code(): string;

    public function title(): string;

    public function description(): string;

    /** Имя иконки для приложения. */
    public function icon(): string;

    /** Группа показа: first_steps | wins | rating | variety | together. */
    public function group(): string;

    /**
     * Металл медали: bronze | silver | gold.
     *
     * Закреплён за значком навсегда и зависит от того, насколько трудно его
     * взять. Не от живой статистики: иначе медаль меняла бы цвет под игроком,
     * а награда так не работает.
     */
    public function tier(): string;

    public function target(): int;

    /** Сколько уже сделано. Никогда не больше target. */
    public function progress(PlayerHistory $history): int;
}
