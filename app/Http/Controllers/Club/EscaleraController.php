<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\EscaleraMatch;
use App\Models\Tournament;
use App\Services\EscaleraService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/**
 * Проведение турнира «Ladder» в вебе: посев, ввод счетов, закрытие раундов,
 * следующий раунд и завершение с наградами. Вся логика формата — в
 * EscaleraService, здесь только приём запросов и вьюхи.
 */
class EscaleraController extends Controller
{
    /** Клуб текущего администратора; у супер-админа клуба нет — он видит все. */
    private function getClub()
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return null;
        }

        if ($user->isClubModerator()) {
            return $user->moderatorClubs()->first();
        }

        return $user->adminClubs()->first();
    }

    /** Турнир должен быть эскалерой и принадлежать клубу администратора. */
    private function authorizeTournament(Tournament $tournament): void
    {
        abort_unless($tournament->isEscalera(), 404);

        $club = $this->getClub();
        if ($club && $tournament->club_id != $club->id) {
            abort(403);
        }
    }

    /**
     * Ключ, под которым в сессии лежит сохранённая расстановка до старта.
     * Именно в сессии, а не в базе: расстановка живёт только в текущем браузере
     * администратора и только до старта турнира.
     */
    private function seedingSessionKey(Tournament $tournament): string
    {
        return 'escalera_seeding.' . $tournament->id;
    }

    /**
     * Экран турнира: корты раундов, таблица, награды.
     */
    public function show(Tournament $tournament)
    {
        $this->authorizeTournament($tournament);

        $tournament->load([
            'club',
            'participants',
            'escaleraPlayers.user',
            'escaleraRounds.courts.matches.team1Player1',
            'escaleraRounds.courts.matches.team1Player2',
            'escaleraRounds.courts.matches.team2Player1',
            'escaleraRounds.courts.matches.team2Player2',
        ]);

        $service = app(EscaleraService::class);

        // Имена игроков для карточек кортов: берём из уже загруженных участников формата.
        $users = $tournament->escaleraPlayers->pluck('user', 'user_id');

        $standings = $service->standings($tournament);
        $currentRound = $service->currentRound($tournament);
        $canCloseRound = $service->canCloseRound($tournament);
        $canFinish = $service->canFinishTournament($tournament);
        // Превью считаем только когда закрытие реально доступно —
        // иначе ранжирование пошло бы по незаполненным счетам.
        $preview = $canCloseRound ? $service->previewRoundClose($tournament) : [];
        $awards = $tournament->status === 'completed' ? $service->awards($tournament) : null;
        // Следующий раунд возможен, когда последний уже закрыт.
        $lastRound = $tournament->escaleraRounds->last();
        $canGenerateNext = $tournament->status === 'in_progress'
            && $lastRound !== null
            && $lastRound->isCompleted();

        return view('club.tournaments.escalera.show', compact(
            'tournament',
            'users',
            'standings',
            'currentRound',
            'canCloseRound',
            'canGenerateNext',
            'canFinish',
            'preview',
            'awards'
        ));
    }

    /**
     * Стартовая расстановка: игроки по кортам сверху вниз, с ручной перестановкой.
     *
     * По умолчанию раскладываем «змейкой» — тем же методом сервиса, что работает
     * при старте без ручной расстановки. Экран всегда отправляет свой порядок,
     * поэтому расклад здесь и расклад в сервисе обязаны совпадать.
     */
    public function seeding(Tournament $tournament)
    {
        $this->authorizeTournament($tournament);

        if ($tournament->status !== 'open') {
            return redirect()->route('club.tournaments.show', $tournament);
        }

        // Игроки без рейтинга ставятся ниже всех — как и в посеве сервиса.
        $participants = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->orderByRaw('COALESCE(rating, 0) DESC')
            ->orderBy('users.id')
            ->get();

        $courtsCount = (int) $tournament->courts_count;
        $needed = $courtsCount * 4;
        $ready = $courtsCount >= 2 && $participants->count() === $needed;

        if ($ready) {
            $participants = collect(
                app(EscaleraService::class)->snakeOrder($participants->all(), $courtsCount)
            );
        }

        // Сохранённая ранее расстановка (кнопка «Сохранить расстановку»).
        $savedOrder = session($this->seedingSessionKey($tournament), []);
        if ($ready && !empty($savedOrder)) {
            $participants = $this->applySavedOrder($participants, $savedOrder);
        }

        return view('club.tournaments.escalera.seeding', compact(
            'tournament',
            'participants',
            'courtsCount',
            'needed',
            'ready'
        ));
    }

    /**
     * Запомнить расстановку, не стартуя турнир: администратор может разложить
     * игроков заранее и вернуться к экрану позже — в этом же браузере.
     */
    public function saveSeeding(Request $request, Tournament $tournament)
    {
        $this->authorizeTournament($tournament);

        if ($tournament->status !== 'open') {
            return redirect()->route('club.tournaments.show', $tournament);
        }

        $order = $this->orderFromRequest($request);
        session([$this->seedingSessionKey($tournament) => $order]);

        return redirect()->route('club.escalera.seeding', $tournament)
            ->with('success', 'Расстановка запомнена в этом браузере до старта турнира. Турнир пока не начат.');
    }

    /**
     * Старт турнира с учётом ручной расстановки (order[] — id сверху вниз).
     */
    public function start(Request $request, Tournament $tournament, EscaleraService $service)
    {
        $this->authorizeTournament($tournament);

        $order = $this->orderFromRequest($request);

        if ($service->startTournament($tournament, $order ?: null)) {
            session()->forget($this->seedingSessionKey($tournament));

            return redirect()->route('club.tournaments.show', $tournament)
                ->with('success', 'Турнир начат');
        }

        return redirect()->route('club.tournaments.show', $tournament)
            ->with('error', 'Не удалось начать турнир: участников должно быть ровно кортов × 4, а турнир — открытым');
    }

    /**
     * Счёт короткого матча. Сумма очков двух команд должна быть равна
     * заданному в турнире числу — иначе счёт не сохраняется.
     */
    public function saveScore(Request $request, EscaleraMatch $match, EscaleraService $service)
    {
        $tournament = $match->court->round->tournament;
        $this->authorizeTournament($tournament);

        $validated = $request->validate([
            'team1_score' => 'required|integer|min:0|max:99',
            'team2_score' => 'required|integer|min:0|max:99',
        ]);

        try {
            $service->saveMatchResult($match, (int) $validated['team1_score'], (int) $validated['team2_score']);
        } catch (QueryException $e) {
            // QueryException наследует RuntimeException, поэтому ловим его раньше
            // своих исключений — иначе админ увидел бы сырой текст SQL-ошибки.
            report($e);

            return back()->withInput()->with('error', 'Не удалось сохранить счёт. Попробуйте ещё раз.');
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withInput()->withErrors(['team1_score' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Не удалось сохранить счёт. Попробуйте ещё раз.');
        }

        return back()->with('success', 'Счёт сохранён');
    }

    /**
     * Закрыть раунд и сразу создать следующий.
     *
     * Для админа это одно действие «Сгенерировать раунд»: закрытие без
     * генерации оставляло турнир в промежуточном состоянии, из которого
     * нужно было нажать ещё одну кнопку.
     */
    public function closeRound(Tournament $tournament, EscaleraService $service)
    {
        $this->authorizeTournament($tournament);

        if (!$service->canCloseRound($tournament)) {
            return back()->with('error', 'Сначала внесите счета всех матчей на всех кортах');
        }

        try {
            $ok = $service->closeRound($tournament);
            if ($ok) {
                $service->generateNextRound($tournament);
            }
        } catch (QueryException $e) {
            // Ловим раньше RuntimeException (QueryException — его наследник),
            // чтобы при двойном клике не показать админу текст SQL-ошибки.
            report($e);

            return back()->with('error', 'Не удалось создать раунд. Обновите страницу и попробуйте ещё раз.');
        } catch (RuntimeException $e) {
            // Например, после перемещений на корте оказалось не четверо.
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Не удалось создать раунд. Обновите страницу и попробуйте ещё раз.');
        }

        if (!$ok) {
            return back()->with('error', 'Не удалось закрыть раунд');
        }

        return back()->with('success', 'Раунд закрыт, игроки разъехались по кортам — следующий раунд готов');
    }

    /**
     * Следующий раунд по результатам перемещений.
     */
    public function nextRound(Tournament $tournament, EscaleraService $service)
    {
        $this->authorizeTournament($tournament);

        try {
            $ok = $service->generateNextRound($tournament);
        } catch (QueryException $e) {
            // Двойной клик по «Следующий раунд» упирается в уникальный ключ
            // (турнир + номер раунда) — показываем понятный текст, а не SQL.
            report($e);

            return back()->with('error', 'Не удалось создать раунд. Обновите страницу: возможно, он уже создан.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Не удалось создать раунд. Обновите страницу и попробуйте ещё раз.');
        }

        if (!$ok) {
            return back()->with('error', 'Не удалось создать раунд: сначала закройте текущий');
        }

        $roundNumber = (int) $tournament->escaleraRounds()->max('round_number');

        return back()->with('success', "Раунд {$roundNumber} создан");
    }

    /**
     * Завершить турнир: рейтинг по каждому короткому матчу и награды.
     */
    public function finish(Tournament $tournament, EscaleraService $service)
    {
        $this->authorizeTournament($tournament);

        // Раунд с внесёнными счетами закрываем прямо здесь: иначе админу
        // пришлось бы сначала сгенерировать лишний раунд, чтобы добраться
        // до кнопки завершения.
        if (!$service->canFinishTournament($tournament) && $service->canCloseRound($tournament)) {
            try {
                $service->closeRound($tournament);
            } catch (\Throwable $e) {
                report($e);

                return back()->with('error', 'Не удалось закрыть раунд. Обновите страницу и попробуйте ещё раз.');
            }
        }

        if (!$service->canFinishTournament($tournament)) {
            return back()->with('error', 'Сначала внесите счета всех матчей на всех кортах');
        }

        if (!$service->finishTournament($tournament)) {
            return back()->with('error', 'Ошибка завершения турнира');
        }

        // Триггер верификации — как и в остальных форматах.
        $tournament->recalculateParticipantsVerification(auth()->id(), $tournament->club_id);

        return redirect()->route('club.tournaments.show', $tournament)
            ->with('success', 'Турнир завершён, рейтинг начислен');
    }

    /**
     * order[] из запроса — список id игроков сверху вниз.
     *
     * @return array<int, int>
     */
    private function orderFromRequest(Request $request): array
    {
        $order = $request->input('order', []);

        return is_array($order) ? array_values(array_map('intval', $order)) : [];
    }

    /**
     * Переставить участников по сохранённой расстановке; забытые дописываются
     * в конец в исходном порядке (по рейтингу).
     *
     * @param  array<int, int> $order
     */
    private function applySavedOrder($participants, array $order)
    {
        $byId = $participants->keyBy('id');
        $ordered = collect();

        foreach ($order as $userId) {
            if ($byId->has($userId) && !$ordered->has($userId)) {
                $ordered->put($userId, $byId->get($userId));
            }
        }
        foreach ($participants as $user) {
            if (!$ordered->has($user->id)) {
                $ordered->put($user->id, $user);
            }
        }

        return $ordered->values();
    }
}
