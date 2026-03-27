<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckClubFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $club = $user->isClubModerator()
            ? $user->moderatorClubs()->first()
            : $user->adminClubs()->first();

        if ($club && !$club->hasFeature($feature)) {
            abort(403, 'Этот раздел отключён для вашего клуба');
        }

        return $next($request);
    }
}
