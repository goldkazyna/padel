<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tournament;
use App\Models\User;
use App\Models\Club;
use App\Services\AmericanoFlexService;

$svc = app(AmericanoFlexService::class);
$club = Club::first();
$users = User::limit(16)->get();

function runSim($svc, $club, $users, int $playerCount, int $courts, int $rounds): array {
    $t = Tournament::create([
        'club_id'=>$club->id,'name'=>'Flex Verify','type'=>'americano_flex',
        'start_date'=>now()->addDay(),'min_level'=>1,'max_level'=>5,
        'max_participants'=>$playerCount,'courts_count'=>$courts,'status'=>'open','is_rated'=>false,
    ]);
    foreach ($users->take($playerCount) as $u) $t->participants()->attach($u->id, ['status'=>'registered']);
    $svc->startTournament($t->fresh());

    for ($r = 1; $r <= $rounds; $r++) {
        $t->refresh();
        $round = $t->americanoFlexRounds()->reorder('round_number','desc')->first();
        foreach ($round->matches as $m) {
            if ($m->status !== 'completed') $svc->saveMatchResult($m, rand(6,16), rand(6,16));
        }
        if ($r < $rounds) {
            if (!$svc->canGenerateNextRound($t->fresh())) break;
            $svc->generateNextRound($t->fresh());
        }
    }

    $partner = []; $opp = []; $bye = [];
    $rs = $t->americanoFlexRounds()->with('matches','byes')->get();
    foreach ($rs as $round) {
        foreach ($round->matches as $m) {
            foreach ([[$m->team1_player1_id,$m->team1_player2_id],[$m->team2_player1_id,$m->team2_player2_id]] as $p) {
                sort($p); $partner["{$p[0]}-{$p[1]}"] = ($partner["{$p[0]}-{$p[1]}"]??0)+1;
            }
            $t1=[$m->team1_player1_id,$m->team1_player2_id]; $t2=[$m->team2_player1_id,$m->team2_player2_id];
            foreach ($t1 as $a) foreach ($t2 as $b) { $x=[$a,$b]; sort($x); $opp["{$x[0]}-{$x[1]}"]=($opp["{$x[0]}-{$x[1]}"]??0)+1; }
        }
        foreach ($round->byes as $b) $bye[$b->user_id] = ($bye[$b->user_id]??0)+1;
    }
    $partnerRepeats = array_sum(array_map(fn($c)=>max(0,$c-1), $partner));
    $byeVals = array_values($bye);
    $byeSpread = $byeVals ? (max($byeVals)-min($byeVals)) : 0;

    $t->participants()->detach();
    \App\Models\AmericanoFlexPairHistory::where('tournament_id',$t->id)->delete();
    foreach ($rs as $round) { $round->matches()->delete(); }
    \App\Models\AmericanoFlexBye::whereIn('americano_flex_round_id', $rs->pluck('id'))->delete();
    $t->americanoFlexRounds()->delete();
    $t->americanoFlexPlayers()->delete();
    $t->delete();

    return [
        'rounds'=>$rs->count(),
        'partnerRepeats'=>$partnerRepeats,
        'maxPartner'=>$partner?max($partner):0,
        'oppMax'=>$opp?max($opp):0,
        'byeSpread'=>$byeSpread,
    ];
}

// расклады + сколько раундов гоняем (включая выход за таблицу — там должен включиться алгоритм)
$cases = [
    [10,2,11], // ровно длина таблицы
    [10,2,14], // за пределами таблицы — алгоритм
    [12,2,11],
    [8,2,7],
    [12,3,11],
];
echo "ЧЕРЕЗ РЕАЛЬНЫЙ СЕРВИС (таблицы + fallback):\n";
foreach ($cases as [$pc,$ct,$rnd]) {
    if ($users->count() < $pc) { echo "  $pc/$ct: мало юзеров\n"; continue; }
    $res = runSim($svc, $club, $users, $pc, $ct, $rnd);
    echo sprintf("  %d игроков / %d корта / %d раундов: повторов_партнёров=%d, макс_партнёр=%d, макс_соперник=%d, разброс_отдыха=%d\n",
        $pc, $ct, $res['rounds'], $res['partnerRepeats'], $res['maxPartner'], $res['oppMax'], $res['byeSpread']);
}
