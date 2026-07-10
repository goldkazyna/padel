<?php
/**
 * Независимая проверка рейтинга Round Robin.
 * Запуск на сервере:  php rr_verify.php <tournament_id>
 *
 * Скрипт НЕ зовёт RoundRobinService — он заново реализует формулу ELO
 * и сверяет результат с тем, что система записала в rating_history.
 */
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tournament;
use App\Models\RatingHistory;

$id = (int) ($argv[1] ?? 0);
$t = Tournament::with('roundRobinPlayers.user')->find($id);
if (!$t) { echo "Турнир $id не найден\n"; exit(1); }
if (!$t->isRoundRobin()) { echo "Турнир $id не Round Robin\n"; exit(1); }

echo "Турнир: {$t->name} (#{$t->id}), статус: {$t->status}, рейтинговый: ".($t->is_rated ? "да" : "НЕТ")."\n\n";

// ---- независимая реализация формулы (копия RatingCalculator) ----
$expected = function(float $a, float $b): float {
    $d = abs($a - $b);
    $p = match(true){ $d<=100=>0.50,$d<=250=>0.60,$d<=500=>0.70,$d<=750=>0.80,$d<=1000=>0.90,$d<=1250=>0.95,$d<=1500=>0.975,default=>0.985 };
    return $a >= $b ? $p : (1 - $p);
};
$kf = fn(float $avg): int => match(true){ $avg<2000=>48,$avg<2500=>36,$avg<4000=>24,default=>36 };
$mult = function(int $s1,int $s2): float { if($s1===$s2)return 1.0; $diff=abs($s1-$s2); $sum=$s1+$s2; return $sum>0?1+($diff/$sum)*0.5:1.0; };

// текущий рейтинг = rating_before (как в finishTournament)
$cur = [];
$before = [];
foreach ($t->roundRobinPlayers as $p) {
    $cur[$p->user_id] = (int) $p->rating_before;
    $before[$p->user_id] = (int) $p->rating_before;
}

// проходим матчи в том же порядке: раунды по номеру, матчи по корту
foreach ($t->roundRobinRounds()->orderBy('round_number')->get() as $round) {
    foreach ($round->matches()->orderBy('court_number')->get() as $m) {
        if ($m->status !== 'completed') continue;
        $ids = [$m->team1_player1_id,$m->team1_player2_id,$m->team2_player1_id,$m->team2_player2_id];
        foreach ($ids as $pid) if (!isset($cur[$pid])) continue 2;
        $t1 = ($cur[$ids[0]]+$cur[$ids[1]])/2;
        $t2 = ($cur[$ids[2]]+$cur[$ids[3]])/2;
        $a1 = $m->team1_score > $m->team2_score ? 1.0 : ($m->team2_score > $m->team1_score ? 0.0 : 0.5);
        $a2 = 1.0 - $a1; if ($a1 === 0.5) $a2 = 0.5;
        $k = max($kf($t1), $kf($t2));
        $mu = $mult((int)$m->team1_score, (int)$m->team2_score);
        $c1 = (int) round($k*$mu*($a1-$expected($t1,$t2)));
        $c2 = (int) round($k*$mu*($a2-$expected($t2,$t1)));
        if ($a1===1.0 && $c1<1) $c1=1;
        if ($a2===1.0 && $c2<1) $c2=1;
        $cur[$ids[0]]=max(1000,$cur[$ids[0]]+$c1);
        $cur[$ids[1]]=max(1000,$cur[$ids[1]]+$c1);
        $cur[$ids[2]]=max(1000,$cur[$ids[2]]+$c2);
        $cur[$ids[3]]=max(1000,$cur[$ids[3]]+$c2);
    }
}

// сверяем с rating_history
printf("%-14s %8s %10s %12s %10s %s\n", "Игрок","было","мой расчёт","система(БД)","Δ","совпало");
echo str_repeat('-', 70)."\n";
$ok = true;
foreach ($t->roundRobinPlayers as $p) {
    $uid = $p->user_id;
    $myDelta = $cur[$uid] - $before[$uid];
    $myAfter = max(1000, ($before[$uid]) + $myDelta);
    $rh = RatingHistory::where('tournament_id',$t->id)->where('user_id',$uid)->first();
    $sysAfter = $rh ? (int)$rh->rating_after : null;
    $sysDelta = $rh ? (int)$rh->change : null;
    $match = ($sysAfter !== null && abs($myDelta - $sysDelta) <= 1) ? "ДА" : "НЕТ <==";
    if ($match !== "ДА") $ok = false;
    printf("%-14s %8d %10d %12s %10s %s\n",
        $p->user->name, $before[$uid], $myAfter,
        $sysAfter===null?'—':$sysAfter,
        ($myDelta>=0?'+':'').$myDelta,
        $match);
}
echo "\n".($ok ? "ВСЁ СОВПАЛО ✓ рейтинг посчитан верно" : "ЕСТЬ РАСХОЖДЕНИЯ — смотри строки с <==")."\n";
echo "\n(прим.: «было» = rating_before, зафиксированный на старте; система применяет дельту к текущему рейтингу игрока на момент завершения)\n";
