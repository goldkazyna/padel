<?php

namespace App\Http\Middleware;

use App\Services\ShiftService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Менеджер без открытой смены попадает только на чек-лист.
 *
 * Админов клуба и супер-админа не трогаем: они не работают в смену, а
 * блокировка им мешала бы управлять клубом.
 */
class RequireOpenShift
{
    public function __construct(private ShiftService $shifts)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user || !$user->isClubModerator()) {
            return $next($request);
        }

        // Сами чек-листы пропускаем — на них middleware и перенаправляет.
        if ($request->routeIs('club.shift.*')) {
            return $next($request);
        }

        $club = $user->moderatorClubs()->first();
        if (!$club || !$club->hasFeature('shifts')) {
            return $next($request);
        }

        $shift = $this->shifts->currentShift($club, $user);

        // Смену закрывает менеджер кнопкой, и только он. Раньше в полночь
        // система сама гнала на чек-лист закрытия — ночная смена рвалась
        // пополам прямо в разгар работы, хотя корты работают за полночь.

        if (!$shift && $this->shifts->needsOpening($club, $user)) {
            return redirect()->route('club.shift.opening');
        }

        return $next($request);
    }
}
