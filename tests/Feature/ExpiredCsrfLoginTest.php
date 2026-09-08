<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

/**
 * Протухший токен на входе не должен упираться в «419 Page Expired».
 *
 * Форма входа висит открытой, человек переключается между аккаунтами или
 * возвращается кнопкой «Назад» — токен от прежней сессии уже не годится.
 * Голая страница 419 никуда не ведёт, и в неё попадали в том числе клиенты.
 */
class ExpiredCsrfLoginTest extends TestCase
{
    public function test_протухший_токен_возвращает_на_форму(): void
    {
        $request = Request::create('https://padel-p.kz/login', 'POST', [
            'email' => 'club@padel.kz',
            'password' => 'секрет',
        ]);
        $request->headers->set('referer', 'https://padel-p.kz/login');
        $request->setLaravelSession($this->app['session']->driver());

        $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/login', $response->headers->get('Location'));
    }

    public function test_фоновому_запросу_отвечаем_json(): void
    {
        $request = Request::create(
            'https://padel-p.kz/club/tournaments/1/pairs/seat/2',
            'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']
        );
        $request->setLaravelSession($this->app['session']->driver());

        $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException());

        $this->assertSame(419, $response->getStatusCode());
        $this->assertSame(
            'Страница устарела. Обновите её и повторите.',
            json_decode($response->getContent(), true)['message'] ?? null
        );
    }

    public function test_страница_входа_не_кешируется(): void
    {
        // Иначе кнопка «Назад» после выхода отдаёт форму со старым токеном.
        $this->get('/login')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
    }
}
