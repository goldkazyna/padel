<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\League;
use App\Models\LeaguePlayer;
use App\Models\Tournament;
use App\Models\User;
use App\Support\LeagueStandings;
use Illuminate\Http\Request;

/**
 * Лиги клуба: серия турниров с общей таблицей.
 *
 * Этап лиги — обычный турнир Americano Flex, он создаётся и проводится теми
 * же экранами. Здесь только сама лига: состав, список этапов и сводный зачёт.
 */
class LeagueController extends Controller
{
    private function getClub(): ?Club
    {
        $user = auth()->user();
        if (!$user) return null;
        if ($user->isSuperAdmin()) return Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();

        return $user->adminClubs()->first();
    }

    /** Лига принадлежит клубу пользователя — иначе смотреть нечего. */
    private function guard(League $league): Club
    {
        $club = $this->getClub();
        if (!$club || (int) $league->club_id !== (int) $club->id) {
            abort(403);
        }

        return $club;
    }

    public function index()
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $leagues = League::where('club_id', $club->id)
            ->withCount(['stages', 'activePlayers as players_count'])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        return view('club.leagues.index', compact('club', 'leagues'));
    }

    public function create()
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        return view('club.leagues.create', compact('club'));
    }

    public function store(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $data = $this->validated($request);
        $data['club_id'] = $club->id;
        $data['creator_id'] = auth()->id();
        $data['status'] = 'open';

        $league = League::create($data);

        return redirect()->route('club.leagues.show', $league)
            ->with('success', 'Лига создана. Теперь добавьте этапы.');
    }

    public function show(League $league)
    {
        $club = $this->guard($league);

        $league->load(['stages' => fn ($q) => $q->orderBy('league_stage')]);

        return view('club.leagues.show', [
            'club' => $club,
            'league' => $league,
            'standings' => LeagueStandings::build($league),
            'summary' => LeagueStandings::summary($league),
            'players' => $league->players()->with('user')->get()
                ->sortBy(fn ($p) => $p->user->name ?? '')
                ->values(),
        ]);
    }

    public function edit(League $league)
    {
        $club = $this->guard($league);

        return view('club.leagues.edit', compact('club', 'league'));
    }

    public function update(Request $request, League $league)
    {
        $this->guard($league);

        $league->update($this->validated($request));

        return redirect()->route('club.leagues.show', $league)
            ->with('success', 'Лига обновлена');
    }

    /** Сменить статус: открыть регистрацию, начать, завершить, отменить. */
    public function status(Request $request, League $league)
    {
        $this->guard($league);

        $validated = $request->validate([
            'status' => 'required|in:draft,open,in_progress,completed,cancelled',
        ]);

        $league->update($validated);

        return back()->with('success', 'Статус лиги изменён');
    }

    /**
     * Создать этап — обычный турнир Americano Flex, привязанный к лиге.
     *
     * Состав лиги сразу записывается в турнир: люди записывались в лигу
     * целиком, а не в каждый этап отдельно. Организатор правит состав перед
     * стартом обычным экраном участников.
     */
    public function addStage(Request $request, League $league)
    {
        $club = $this->guard($league);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'max_participants' => 'required|integer|min:4|max:64',
            'price' => 'nullable|numeric|min:0',
            'courts_count' => 'nullable|integer|min:1|max:16',
        ]);

        $stage = $league->nextStageNumber();

        $tournament = Tournament::create([
            'club_id' => $club->id,
            'league_id' => $league->id,
            'league_stage' => $stage,
            'creator_id' => auth()->id(),
            // Название необязательное: пустое поле формы вообще не приходит.
            'name' => $validated['name'] ?? null ?: "{$league->name} — этап {$stage}",
            'type' => 'americano_flex',
            'status' => 'open',
            'start_date' => $validated['start_date'],
            'min_level' => $league->min_level ?? 1,
            'max_level' => $league->max_level ?? 7,
            'max_participants' => $validated['max_participants'],
            // Цена в турнире обязательна; берём из этапа, потом из лиги, иначе ноль.
            'price' => $validated['price'] ?? $league->price ?? 0,
            'courts_count' => $validated['courts_count'] ?? 2,
            'is_rated' => $league->is_rated,
        ]);

        $this->fillFromLeague($league, $tournament);

        if ($league->status === 'open') {
            $league->update(['status' => 'in_progress']);
        }

        return redirect()->route('club.tournaments.show', $tournament)
            ->with('success', "Этап {$stage} создан, состав лиги записан");
    }

    /** Записать состав лиги в турнир этапа. */
    private function fillFromLeague(League $league, Tournament $tournament): void
    {
        $userIds = $league->activePlayers()->pluck('user_id');
        if ($userIds->isEmpty()) {
            return;
        }

        $rows = $userIds->take($tournament->max_participants)
            ->mapWithKeys(fn ($id) => [$id => ['status' => 'registered', 'created_at' => now(), 'updated_at' => now()]])
            ->all();

        $tournament->participants()->syncWithoutDetaching($rows);
    }

    /**
     * Поиск игроков для состава лиги.
     *
     * Свой эндпоинт, а не админский: тот доступен только супер-админу, а
     * лигу собирает клуб. Поиск умный — «Денис» находит и Denis.
     */
    public function searchPlayers(Request $request, League $league)
    {
        $this->guard($league);

        $q = trim((string) $request->get('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['players' => []]);
        }

        $digits = preg_replace('/\D/', '', $q);
        $inLeague = $league->activePlayers()->pluck('user_id');

        $players = User::human()
            ->where(function ($w) use ($q, $digits) {
                foreach (\App\Support\NameSearch::variants($q) as $variant) {
                    $w->orWhere('name', 'like', "%{$variant}%");
                }
                if (strlen($digits) >= 3) {
                    $w->orWhere('phone', 'like', "%{$digits}%");
                }
            })
            ->whereNotIn('id', $inLeague)
            ->tap(fn ($w) => \App\Support\NameSearch::orderExactFirst($w, $q, ['name']))
            ->limit(10)
            ->get(['id', 'name', 'phone', 'level', 'rating', 'avatar']);

        return response()->json(['players' => $players]);
    }

    public function addPlayer(Request $request, League $league)
    {
        $this->guard($league);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        LeaguePlayer::updateOrCreate(
            ['league_id' => $league->id, 'user_id' => $validated['user_id']],
            ['status' => 'registered', 'joined_at' => now(), 'left_at' => null]
        );

        return back()->with('success', 'Игрок добавлен в лигу');
    }

    /**
     * Убрать игрока из состава.
     *
     * Уже сыгранные этапы не трогаем: очки заработаны, и из сводной таблицы
     * они никуда не деваются — иначе история лиги переписывалась бы задним
     * числом.
     */
    public function removePlayer(League $league, User $user)
    {
        $this->guard($league);

        LeaguePlayer::where('league_id', $league->id)
            ->where('user_id', $user->id)
            ->update(['status' => 'left', 'left_at' => now()]);

        return back()->with('success', 'Игрок убран из состава');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'stages_planned' => 'required|integer|min:2|max:30',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'min_level' => 'nullable|numeric|min:1|max:7',
            'max_level' => 'nullable|numeric|min:1|max:7|gte:min_level',
            'max_players' => 'nullable|integer|min:4|max:200',
            'price' => 'nullable|integer|min:0',
            'is_rated' => 'nullable|boolean',
        ]) + ['is_rated' => $request->boolean('is_rated')];
    }
}
