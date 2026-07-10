<?php
/**
 * Оффлайн-генератор идеальных сеток для Americano Flex.
 * Под заданный (N игроков, C кортов) ищет расписание на R раундов так, чтобы:
 *   - повторов партнёров было минимум (0 где математически возможно),
 *   - максимум встреч с одним соперником был как можно ниже (цель ≤2),
 *   - отдых был распределён ровно (разница ≤1).
 * Работает со слотами 0..N-1 (реальных людей подставим позже).
 */

function pairKey(int $a, int $b): string { return $a < $b ? "$a-$b" : "$b-$a"; }

/** Совместный оптимальный подбор пар для набора играющих слотов (рекурсия с фиксацией первого). */
function bestPartition(array $ids, array $partnerUsed, array $oppUsed): array {
    if (count($ids) < 4) return ['cost' => 0, 'courts' => []];
    $first = $ids[0];
    $rest = array_values(array_slice($ids, 1));
    $n = count($rest);
    $best = ['cost' => PHP_INT_MAX, 'courts' => []];
    for ($i = 0; $i < $n; $i++)
    for ($j = $i + 1; $j < $n; $j++)
    for ($k = $j + 1; $k < $n; $k++) {
        $four = [$first, $rest[$i], $rest[$j], $rest[$k]];
        // 3 варианта разбить четвёрку на 2 команды
        $splits = [
            [[$four[0],$four[1]],[$four[2],$four[3]]],
            [[$four[0],$four[2]],[$four[1],$four[3]]],
            [[$four[0],$four[3]],[$four[1],$four[2]]],
        ];
        $bestSplit = null; $bestSplitCost = PHP_INT_MAX;
        foreach ($splits as $s) {
            [$t1, $t2] = $s;
            $cost = 0;
            // партнёры — дорого
            $cost += 1000 * ($partnerUsed[pairKey($t1[0],$t1[1])] ?? 0);
            $cost += 1000 * ($partnerUsed[pairKey($t2[0],$t2[1])] ?? 0);
            // соперники — дёшево, но растёт квадратично чтобы не копить >2
            foreach ([$t1[0],$t1[1]] as $a) foreach ([$t2[0],$t2[1]] as $b) {
                $cnt = $oppUsed[pairKey($a,$b)] ?? 0;
                $cost += $cnt * $cnt;
            }
            if ($cost < $bestSplitCost) { $bestSplitCost = $cost; $bestSplit = $s; }
        }
        $remaining = [];
        for ($t = 0; $t < $n; $t++) if ($t !== $i && $t !== $j && $t !== $k) $remaining[] = $rest[$t];
        $sub = bestPartition($remaining, $partnerUsed, $oppUsed);
        $total = $bestSplitCost + $sub['cost'];
        if ($total < $best['cost']) {
            $best = ['cost' => $total, 'courts' => array_merge([$bestSplit], $sub['courts'])];
        }
    }
    return $best;
}

function buildSchedule(int $N, int $C, int $R, int $restarts, int $seed): array {
    mt_srand($seed);
    $playPer = $C * 4;
    $byePer = $N - $playPer;
    $bestSchedule = null; $bestScore = null;

    for ($r = 0; $r < $restarts; $r++) {
        $partnerUsed = []; $oppUsed = []; $byeCount = array_fill(0, $N, 0);
        $schedule = [];
        for ($round = 0; $round < $R; $round++) {
            // выбираем отдыхающих: наименее отдыхавшие, случайный tie-break
            $order = range(0, $N - 1);
            usort($order, function($a, $b) use ($byeCount) {
                if ($byeCount[$a] !== $byeCount[$b]) return $byeCount[$a] <=> $byeCount[$b];
                return mt_rand(-1, 1);
            });
            $byes = $byePer > 0 ? array_slice($order, 0, $byePer) : [];
            $playing = array_values(array_diff(range(0, $N - 1), $byes));
            shuffle($playing);

            $part = bestPartition($playing, $partnerUsed, $oppUsed);
            $courts = $part['courts'];

            // фиксируем историю
            foreach ($courts as $crt) {
                [$t1, $t2] = $crt;
                $partnerUsed[pairKey($t1[0],$t1[1])] = ($partnerUsed[pairKey($t1[0],$t1[1])] ?? 0) + 1;
                $partnerUsed[pairKey($t2[0],$t2[1])] = ($partnerUsed[pairKey($t2[0],$t2[1])] ?? 0) + 1;
                foreach ([$t1[0],$t1[1]] as $a) foreach ([$t2[0],$t2[1]] as $b) {
                    $oppUsed[pairKey($a,$b)] = ($oppUsed[pairKey($a,$b)] ?? 0) + 1;
                }
            }
            foreach ($byes as $b) $byeCount[$b]++;
            $schedule[] = ['courts' => $courts, 'byes' => $byes];
        }
        $score = scoreSchedule($schedule, $N);
        if ($bestScore === null || compareScore($score, $bestScore) < 0) {
            $bestScore = $score; $bestSchedule = $schedule;
        }
        if ($bestScore['partnerRepeats'] === 0 && $bestScore['oppMax'] <= 2 && $bestScore['byeSpread'] <= 1) break;
    }
    return ['schedule' => $bestSchedule, 'score' => $bestScore];
}

function scoreSchedule(array $schedule, int $N): array {
    $partner = []; $opp = []; $byeCount = array_fill(0, $N, 0);
    foreach ($schedule as $rd) {
        foreach ($rd['courts'] as $crt) {
            [$t1, $t2] = $crt;
            $partner[pairKey($t1[0],$t1[1])] = ($partner[pairKey($t1[0],$t1[1])] ?? 0) + 1;
            $partner[pairKey($t2[0],$t2[1])] = ($partner[pairKey($t2[0],$t2[1])] ?? 0) + 1;
            foreach ([$t1[0],$t1[1]] as $a) foreach ([$t2[0],$t2[1]] as $b) {
                $opp[pairKey($a,$b)] = ($opp[pairKey($a,$b)] ?? 0) + 1;
            }
        }
        foreach ($rd['byes'] as $b) $byeCount[$b]++;
    }
    $partnerRepeats = array_sum(array_map(fn($c) => max(0, $c - 1), $partner));
    return [
        'partnerRepeats' => $partnerRepeats,
        'partnerMax' => $partner ? max($partner) : 0,
        'oppMax' => $opp ? max($opp) : 0,
        'byeSpread' => max($byeCount) - min($byeCount),
        'byeCount' => $byeCount,
    ];
}

// сравнение: сначала повторы партнёров, потом макс соперник, потом разброс отдыха
function compareScore(array $a, array $b): int {
    if ($a['partnerRepeats'] !== $b['partnerRepeats']) return $a['partnerRepeats'] <=> $b['partnerRepeats'];
    if ($a['oppMax'] !== $b['oppMax']) return $a['oppMax'] <=> $b['oppMax'];
    return $a['byeSpread'] <=> $b['byeSpread'];
}

/** Перевод schedule(courts) -> flat: ['play'=>[..8/12..], 'byes'=>[..]] на раунд. */
function toFlat(array $schedule): array {
    $flat = [];
    foreach ($schedule as $rd) {
        $play = [];
        foreach ($rd['courts'] as $crt) { [$t1,$t2] = $crt; $play[]=$t1[0]; $play[]=$t1[1]; $play[]=$t2[0]; $play[]=$t2[1]; }
        $flat[] = ['play' => $play, 'byes' => $rd['byes']];
    }
    return $flat;
}

/** Энергия flat-схемы: партнёры (очень дорого) + соперники>2 + разброс отдыха. */
function energyFlat(array $flat, int $N, int $C): array {
    $partner=[]; $opp=[]; $byeCount=array_fill(0,$N,0);
    foreach ($flat as $rd) {
        for ($c=0;$c<$C;$c++) {
            $a=$rd['play'][4*$c]; $b=$rd['play'][4*$c+1]; $d=$rd['play'][4*$c+2]; $e=$rd['play'][4*$c+3];
            $partner[pairKey($a,$b)]=($partner[pairKey($a,$b)]??0)+1;
            $partner[pairKey($d,$e)]=($partner[pairKey($d,$e)]??0)+1;
            foreach ([$a,$b] as $x) foreach ([$d,$e] as $y) $opp[pairKey($x,$y)]=($opp[pairKey($x,$y)]??0)+1;
        }
        foreach ($rd['byes'] as $z) $byeCount[$z]++;
    }
    $pr=array_sum(array_map(fn($c)=>max(0,$c-1),$partner));
    $oppOver=array_sum(array_map(fn($c)=>max(0,$c-2),$opp));
    $oppMax=$opp?max($opp):0;
    $byeSpread=max($byeCount)-min($byeCount);
    // покрытие «каждый с каждым»: пары, которые ни разу не пересеклись
    $cov=[]; foreach($partner as $k=>$v)$cov[$k]=1; foreach($opp as $k=>$v)$cov[$k]=1;
    $uncovered=($N*($N-1)/2)-count($cov);
    $E = 500*$pr + 5000*max(0,$byeSpread-1) + 40*$uncovered + 20*$oppOver;
    return ['E'=>$E,'pr'=>$pr,'oppMax'=>$oppMax,'oppOver'=>$oppOver,'byeSpread'=>$byeSpread,'byeCount'=>$byeCount,'partnerMax'=>$partner?max($partner):0,'uncovered'=>$uncovered];
}

/**
 * Имитация отжига: тасует ТОЛЬКО состав пар среди уже выбранных играющих
 * (свопы внутри раунда). Отдых НЕ трогаем — его расставил билдер, раздавая
 * самым неотдыхавшим, и это сбалансировано на любом префиксе раундов.
 */
function anneal(array $flat, int $N, int $C, int $iters, int $seed): array {
    mt_srand($seed);
    $R=count($flat); $playLen=4*$C;
    $cur=$flat; $curE=energyFlat($cur,$N,$C); $best=$cur; $bestE=$curE;
    for ($it=0;$it<$iters;$it++) {
        $T = 300.0 * pow(0.02/300.0, $it/$iters);
        $r=mt_rand(0,$R-1);
        // своп двух играющих позиций внутри раунда (отдых неизменен)
        $i=mt_rand(0,$playLen-1); $j=mt_rand(0,$playLen-1); if ($i==$j) continue;
        $tmp=$cur[$r]['play'][$i]; $cur[$r]['play'][$i]=$cur[$r]['play'][$j]; $cur[$r]['play'][$j]=$tmp;
        $newE=energyFlat($cur,$N,$C);
        if ($newE['E']<=$curE['E'] || mt_rand()/mt_getrandmax() < exp(($curE['E']-$newE['E'])/$T)) {
            $curE=$newE; if ($newE['E']<$bestE['E']){$bestE=$newE;$best=$cur;}
        } else { $tmp=$cur[$r]['play'][$i]; $cur[$r]['play'][$i]=$cur[$r]['play'][$j]; $cur[$r]['play'][$j]=$tmp; }
        if ($bestE['pr']==0 && $bestE['oppOver']==0 && $bestE['byeSpread']<=1) break;
    }
    return ['flat'=>$best,'E'=>$bestE];
}

/** flat -> читаемый schedule(courts). */
function flatToSchedule(array $flat, int $C): array {
    $out=[];
    foreach ($flat as $rd) {
        $courts=[];
        for ($c=0;$c<$C;$c++) $courts[]=[[$rd['play'][4*$c],$rd['play'][4*$c+1]],[$rd['play'][4*$c+2],$rd['play'][4*$c+3]]];
        $out[]=['courts'=>$courts,'byes'=>$rd['byes']];
    }
    return $out;
}

/** Полный расчёт одного расклада: возвращает лучшую flat-схему + статы. */
function solveConfig(int $N, int $C, int $R, int $restarts, int $annealIters, array $seeds): array {
    $bestFlat=null; $bestE=null;
    foreach ($seeds as $sd) {
        $base = buildSchedule($N, $C, $R, $restarts, $sd);
        $imp  = anneal(toFlat($base['schedule']), $N, $C, $annealIters, $sd);
        if ($bestE===null || $imp['E']['E']<$bestE['E']) { $bestE=$imp['E']; $bestFlat=$imp['flat']; }
        if ($bestE['pr']==0 && $bestE['oppOver']==0 && $bestE['byeSpread']<=1) break;
    }
    return ['flat'=>$bestFlat,'E'=>$bestE];
}

$path = __DIR__ . '/database/data/americano_flex_schedules.json';
@mkdir(dirname($path), 0777, true);

// Существующие таблицы — НЕ перезатираем, только дополняем/заменяем нужные.
$out = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];

// Какие расклады считать:
//   php flex_schedule_gen.php           -> все 1-3 корта (быстрые настройки)
//   php flex_schedule_gen.php 12-2 10-2 -> только указанные, с ТЯЖЁЛОЙ оптимизацией
$targets = array_slice($argv, 1);
$heavy = !empty($targets);
if ($heavy) {
    $configs = array_map(fn($t) => array_map('intval', explode('-', $t)), $targets);
} else {
    $configs = [];
    for ($C = 1; $C <= 3; $C++) {
        $playPer = 4 * $C;
        for ($N = max(4, $playPer); $N <= $playPer + 6; $N++) $configs[] = [$N, $C];
    }
}

foreach ($configs as [$N, $C]) {
    $pairsPerRound = 2 * $C;
    $totalPairs = $N * ($N - 1) / 2;
    $R = (int) floor($totalPairs / $pairsPerRound);
    $R = max($R, 1);
    $R = min($R, 16);

    // heavy — много рестартов (варьируют выбор отдыхающих → лучше по соперникам)
    // + длинный отжиг. Для одного расклада это всё равно минуты.
    $restarts    = $heavy ? 500 : 50;
    $annealIters = $heavy ? 500000 : 250000;
    $seeds       = $heavy ? [12345, 777, 2024] : [12345, 777, 2024];

    $sol = solveConfig($N, $C, $R, $restarts, $annealIters, $seeds);
    $E = $sol['E'];
    $sched = flatToSchedule($sol['flat'], $C);

    $key = "$N-$C";
    $out[$key] = [
        'players' => $N, 'courts' => $C, 'rounds' => $R,
        'partner_repeats' => $E['pr'], 'opp_max' => $E['oppMax'], 'bye_spread' => $E['byeSpread'],
        'schedule' => $sched,
    ];
    file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    printf("%-7s R=%-2d партнёры_повт=%d  соперник_макс=%d  отдых_разброс=%d\n",
        $key, $R, $E['pr'], $E['oppMax'], $E['byeSpread']);
}

echo "\nГотово. Всего раскладов в файле: ".count($out)." -> $path\n";
