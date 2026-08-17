<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Страницы-лендинги уводят в магазин.
 *
 * Схема market:// и itms-apps:// открывают само приложение магазина.
 * Обычная ссылка при переходе из JS остаётся в браузере — человек видел
 * веб-страницу Play Market вместо магазина.
 */
class StoreRedirectTest extends TestCase
{
    use RefreshDatabase;

    private const ANDROID_UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36';
    private const IOS_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15';

    private function club(): Club
    {
        return Club::create([
            'name' => 'Клуб', 'address' => 'А',
            'waiver_enabled' => true, 'waiver_text' => 'Текст',
        ]);
    }

    /** @return array<int, string> */
    public static function pages(): array
    {
        return [
            'отказ' => ['waiver'],
            'клуб' => ['club'],
            'турнир' => ['tournament'],
        ];
    }

    private function url(string $page): string
    {
        $club = $this->club();

        return match ($page) {
            'waiver' => '/w/' . $club->id,
            'club' => '/c/' . $club->id,
            'tournament' => '/t/' . Tournament::factory()->create(['club_id' => $club->id])->id,
        };
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_android_gets_the_store_app_scheme(string $page): void
    {
        $this->withHeader('User-Agent', self::ANDROID_UA)
            ->get($this->url($page))
            ->assertOk()
            ->assertSee('market://details?id=', false)
            // Обычная ссылка остаётся запасным путём.
            ->assertSee('https://play.google.com/store/apps/details', false);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_ios_gets_the_store_app_scheme(string $page): void
    {
        $this->withHeader('User-Agent', self::IOS_UA)
            ->get($this->url($page))
            ->assertOk()
            ->assertSee('itms-apps://', false)
            ->assertSee('https://apps.apple.com/', false);
    }

    /**
     * Схема пробуется раньше обычной ссылки, иначе смысла в ней нет.
     *
     * Смотрим очередь внутри скрипта, а не порядок в документе: видимая
     * кнопка «Установить приложение» законно стоит выше.
     */
    public function test_scheme_is_tried_before_the_web_link(): void
    {
        $body = $this->withHeader('User-Agent', self::ANDROID_UA)
            ->get('/w/' . $this->club()->id)
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            preg_match(
                '/storeAppUrl;.*?\}, (\d+)\).*?storeWebUrl;.*?\}, (\d+)\)/s',
                $body,
                $m
            ),
            'в скрипте должны стоять оба перехода, схема первой'
        );
        $this->assertLessThan((int) $m[2], (int) $m[1], 'схему надо пробовать раньше');
    }
}
