<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Services\BaliKocService;
use App\Services\EscaleraService;
use App\Services\JustPadelItService;
use App\Services\KingOfCourtService;
use App\Services\MexicanoService;
use Illuminate\Http\RedirectResponse;

/**
 * Пересборка последнего раунда.
 *
 * Форматы, где состав следующего раунда считается от результатов предыдущего,
 * при исправлении счёта остаются с раундом, собранным по неверным данным.
 * Здесь мы удаляем такой раунд и генерируем заново — уже из актуальной таблицы.
 *
 * Американо, Round Robin, Americano Flex и командный сюда не попадают: их
 * раунды не зависят от результатов (расписание известно заранее).
 */
class TournamentRoundController extends Controller
{
    /** Форматы, у которых пересборка вообще имеет смысл. */
    public const REBUILDABLE = [
        'mexicano',
        'king_of_court',
        'just_padel_it',
        'bali_koc',
        'escalera',
    ];

    public function rebuildLast(Tournament $tournament): RedirectResponse
    {
        $user = auth()->user();

        // Доступ как в остальных экранах турнира: клуб владельца, а модератору
        // нужен полный доступ к турнирам.
        $club = $user->isSuperAdmin()
            ? null
            : ($user->isClubModerator()
                ? $user->moderatorClubs()->first()
                : $user->adminClubs()->first());

        if ($club && $tournament->club_id != $club->id) {
            abort(403);
        }
        if ($user->isClubModerator() && (!$club || !$user->hasTournamentsFullAccess($club))) {
            abort(403, 'У вас нет прав на это действие. Обратитесь к админу клуба.');
        }

        if (!in_array($tournament->type, self::REBUILDABLE, true)) {
            return back()->with('error', 'У этого формата раунды не зависят от результатов — пересобирать нечего.');
        }

        if ($tournament->status !== 'in_progress') {
            return back()->with('error', 'Пересобрать раунд можно только у идущего турнира.');
        }

        $done = match ($tournament->type) {
            'mexicano' => app(MexicanoService::class)->rebuildLastRound($tournament),
            'king_of_court' => app(KingOfCourtService::class)->rebuildLastRound($tournament),
            'just_padel_it' => app(JustPadelItService::class)->rebuildLastRound($tournament),
            'bali_koc' => app(BaliKocService::class)->rebuildLastRound($tournament),
            'escalera' => app(EscaleraService::class)->rebuildLastRound($tournament),
            default => false,
        };

        return $done
            ? back()->with('success', 'Раунд пересобран по текущим результатам.')
            : back()->with('error', 'Не удалось пересобрать: первый раунд строится посевом, а не результатами.');
    }
}
