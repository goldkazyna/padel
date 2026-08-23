<?php

namespace Tests\Unit;

use App\Support\NameSearch;
use Tests\TestCase;

/**
 * Поиск по имени должен находить человека независимо от того, каким алфавитом
 * записано имя в профиле и каким его набирают в строке поиска.
 */
class NameSearchTest extends TestCase
{
    public function test_кириллический_запрос_даёт_латинское_написание(): void
    {
        $this->assertContains('denis', NameSearch::variants('Денис'));
    }

    public function test_латинский_запрос_даёт_кириллическое_написание(): void
    {
        $this->assertContains('денис', NameSearch::variants('Denis'));
    }

    public function test_сам_запрос_всегда_первый(): void
    {
        $this->assertSame('Денис', NameSearch::variants('Денис')[0]);
        $this->assertSame('Denis', NameSearch::variants('Denis')[0]);
    }

    public function test_спорные_буквы_дают_оба_написания(): void
    {
        // Жанну пишут и Zhanna, и Janna — ищем по обоим.
        $variants = NameSearch::variants('Жанна');
        $this->assertContains('zhanna', $variants);
        $this->assertContains('janna', $variants);

        // Хасан — Khasan или Hasan.
        $variants = NameSearch::variants('Хасан');
        $this->assertContains('khasan', $variants);
        $this->assertContains('hasan', $variants);
    }

    public function test_е_превращается_в_ye_только_в_начале_слова(): void
    {
        // Ержан — Yerzhan, но Денис — Denis, а не Dyenis.
        $this->assertContains('yerzhan', NameSearch::variants('Ержан'));
        $this->assertNotContains('dyenis', NameSearch::variants('Денис'));
    }

    public function test_сочетания_букв_читаются_целиком(): void
    {
        // «sh» — это ш, а не с + х.
        $this->assertContains('шакир', NameSearch::variants('Shakir'));
        $this->assertContains('жанна', NameSearch::variants('Zhanna'));
        $this->assertContains('чингиз', NameSearch::variants('Chingiz'));
    }

    public function test_имя_и_фамилия_переводятся_обе(): void
    {
        $this->assertContains('ivan petrov', NameSearch::variants('Иван Петров'));
        $this->assertContains('иван петров', NameSearch::variants('Ivan Petrov'));
    }

    public function test_казахские_буквы_поддерживаются(): void
    {
        $variants = NameSearch::variants('Айгүл');
        $this->assertContains('aygul', $variants);

        $variants = NameSearch::variants('Дәурен');
        $this->assertContains('dauren', $variants);
    }

    public function test_пустой_запрос_не_даёт_вариантов(): void
    {
        $this->assertSame([], NameSearch::variants(''));
        $this->assertSame([], NameSearch::variants('   '));
    }

    public function test_запрос_из_цифр_остаётся_как_есть(): void
    {
        $this->assertSame(['77012223344'], NameSearch::variants('77012223344'));
    }

    public function test_число_написаний_ограничено(): void
    {
        // Длинное имя из одних спорных букв не должно давать десятки LIKE.
        $variants = NameSearch::variants('Жүжүхәщяю');
        $this->assertLessThanOrEqual(12, count($variants));
        $this->assertGreaterThan(1, count($variants));
    }

    public function test_буквы_не_теряются_при_ограничении(): void
    {
        // Даже когда ветвление обрезано, написание остаётся полным:
        // длина латинского варианта не меньше числа букв запроса.
        $variants = NameSearch::variants('Жүжүхәщяю');
        foreach (array_slice($variants, 1) as $v) {
            $this->assertGreaterThanOrEqual(9, mb_strlen($v), "написание «{$v}» короче запроса");
        }
    }
}
