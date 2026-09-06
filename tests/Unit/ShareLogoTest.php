<?php

namespace Tests\Unit;

use App\Support\ShareLogo;
use Tests\TestCase;

/**
 * Картинка превью для ссылок, которыми делятся.
 *
 * Логотипы клубов лежат в базе по-разному, а префикс добавлялся всегда —
 * получалось «/logos/logos/x.jpg», и карточка в мессенджере выходила без
 * картинки. 404 отдавался молча, поэтому месяцами этого никто не замечал.
 */
class ShareLogoTest extends TestCase
{
    public function test_путь_со_слешем_не_удваивает_папку(): void
    {
        $this->assertSame(
            asset('logos/davay-padel.jpg'),
            ShareLogo::url('/logos/davay-padel.jpg')
        );
    }

    public function test_путь_без_папки_её_получает(): void
    {
        $this->assertSame(
            asset('logos/pulse.png'),
            ShareLogo::url('pulse.png')
        );
    }

    public function test_готовый_адрес_остаётся_как_есть(): void
    {
        $url = 'https://cdn.example.com/club.png';

        $this->assertSame($url, ShareLogo::url($url));
    }

    public function test_без_логотипа_общая_картинка(): void
    {
        $this->assertSame(asset(ShareLogo::FALLBACK), ShareLogo::url(null));
        $this->assertSame(asset(ShareLogo::FALLBACK), ShareLogo::url('  '));
    }
}
