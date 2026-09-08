<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Запретить браузеру хранить страницу.
 *
 * Форма входа несёт CSRF-токен сессии. Браузер держит её в кеше «назад», и
 * после выхода из аккаунта человек возвращается кнопкой «Назад» на страницу
 * со старым токеном — отправка даёт 419 «Page Expired». С no-store браузер
 * каждый раз запрашивает страницу заново и получает свежий токен.
 */
class NoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
