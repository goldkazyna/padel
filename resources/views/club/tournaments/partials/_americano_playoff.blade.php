{{-- resources/views/club/tournaments/partials/_americano_playoff.blade.php --}}
<hr/>
@if($tournament->isAmericano() && $tournament->hasPlayoff() && $tournament->playoffMatches()->count() > 0)
<div class="mb-4">
    <div class="card-header-dark">
        <h5 class="mb-0"><i class="bi bi-trophy text-warning me-2"></i>Плей-офф</h5>
    </div>
    <div class="card-body-dark">
        @php
            $brackets = $tournament->playoffMatches->groupBy(fn($m) => $m->bracket ?: 'upper');
            $bracketTitles = ['upper' => 'Верхняя сетка', 'lower' => 'Нижняя сетка'];
            $stageOrder = ['Полуфинал' => 'Полуфинал', 'Матч за 3-е место' => 'Матч за 3-е место', 'Финал' => 'Финал'];
        @endphp

        @foreach(['upper','lower'] as $bracketKey)
            @if(isset($brackets[$bracketKey]))
                @php $stages = $brackets[$bracketKey]->groupBy('stage'); @endphp
                @if($brackets->count() > 1)
                    <h6 class="text-secondary mt-3 mb-2">{{ $bracketTitles[$bracketKey] }}</h6>
                @endif
                <div class="playoff-bracket">
                    @foreach($stageOrder as $stageKey => $stageName)
                        @if(isset($stages[$stageKey]))
                            <div class="playoff-stage">
                                <div class="stage-title">{{ $stageName }}</div>
                                <div class="stage-matches">
                                    @foreach($stages[$stageKey] as $match)
                                        @include('club.tournaments.partials._americano_playoff_match', ['match' => $match, 'stageName' => $stageName, 'tournament' => $tournament])
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        @endforeach

        {{-- Победитель --}}
        @php $finalMatch = $tournament->playoffMatches
            ->first(fn($m) => $m->stage === 'Финал' && (($m->bracket ?: 'upper') === 'upper')); @endphp
        @if($finalMatch && $finalMatch->status === 'completed')
            @php
                $winnerP1 = $finalMatch->team1_score > $finalMatch->team2_score ? $finalMatch->team1Player1 : $finalMatch->team2Player1;
                $winnerP2 = $finalMatch->team1_score > $finalMatch->team2_score ? $finalMatch->team1Player2 : $finalMatch->team2Player2;
            @endphp
            <div class="winner-block mt-4">
                <div class="winner-trophy"><i class="bi bi-trophy-fill"></i></div>
                <div class="winner-title">Победители турнира</div>
                <div class="winner-name">{{ $winnerP1->name }} / {{ $winnerP2->name }}</div>
            </div>
        @endif
    </div>
</div>
@endif
<style>
.playoff-stage {
    min-width: 700px;
}
.playoff-team-name{
	    max-width: 700px;
		font-size:35px;
}
.score-team-names{
	font-size:24px;
}
.playoff-score{
	font-size:40px;
}
</style>
