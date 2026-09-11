<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Кого пускаем в отчёты клуба.
 *
 * Владелец и супер-админ — всегда. Менеджера — только если клуб сам это
 * разрешил выключателем в настройках: в отчётах выручка, долги и зарплата
 * тренеров, и по умолчанию они закрыты.
 */
class ClubReportsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();
        if (!$user) {
            return redirect()->route('login');
        }

        if ($user->isSuperAdmin() || $user->isClubAdmin() || $user->adminClubs()->exists()) {
            return $next($request);
        }

        if ($user->isClubModerator()) {
            $club = $user->moderatorClubs()->first();
            if ($club && $club->moderators_can_view_reports) {
                return $next($request);
            }
        }

        abort(403, 'Отчёты доступны администратору клуба');
    }
}
