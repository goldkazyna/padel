<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        // Клубные роли проверяем по настоящим правам: модератором или
        // админом клуба назначают записью в таблице, а роль у человека
        // может остаться прежней (тренер, игрок).
        $allowed = false;
        foreach ($roles as $role) {
            $allowed = match ($role) {
                'super_admin' => $user->isSuperAdmin(),
                'club_admin' => $user->isClubAdmin() || $user->adminClubs()->exists(),
                'club_moderator' => $user->isClubModerator(),
                default => $user->role === $role,
            };
            if ($allowed) {
                break;
            }
        }

        if (!$allowed) {
            abort(403, 'Доступ запрещён');
        }

        return $next($request);
    }
}