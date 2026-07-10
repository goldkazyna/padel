<?php
// Генератор парных расписаний Americano Flex.
// P пар, C кортов. Каждый раунд: 2C пар играют (C матчей), P-2C отдыхают.
// Приоритет: ровный отдых + минимум отдыха подряд, затем разнообразие соперников.
// Запуск: php flex_paired_gen.php   → пишет database/data/americano_flex_paired_schedules.json

// Конфиги (P пар - C кортов). P = игроки/2.
$configs = [
    '3-1', '4-1', '5-1', '6-1',
    '5-2', '6-2', '7-2', '8-2', '9-2', '10-2',
    '7-3', '8-3', '9-3', '10-3', '11-3', '12-3',
    '9-4', '10-4', '11-4', '12-4',
];

mt_srand(20260621);

function genSchedule(int $P, int $C, int $restarts = 4000): array
{
    $playPerRound = min($P, 2 * $C);
    if ($playPerRound % 2 !== 0) $playPerRound--;
    $matchesPerRound = intdiv($playPerRound, 2);
    if ($matchesPerRound < 1) return ['rounds' => 0, 'schedule' => []];

    // Раундов: чтобы покрыть все пары соперников хотя бы раз.
    $totalOppPairs = $P * ($P - 1) / 2;
    $rounds = (int) ceil($totalOppPairs / $matchesPerRound);
    $rounds = max($rounds, $P); // минимум — чтобы отдых распределился

    $best = null;
    $bestScore = null;

    for ($r = 0; $r < $restarts; $r++) {
        $sched = buildOne($P, $C, $rounds, $playPerRound, $matchesPerRound);
        if (!$sched) continue;
        $score = scoreSched($sched, $P, $rounds);
        if ($bestScore === null || cmpScore($score, $bestScore) < 0) {
            $bestScore = $score;
            $best = $sched;
        }
        // Идеал: spread<=1, max_consec<=1, opp covered
        if ($bestScore['bye_spread'] <= 1 && $bestScore['max_consec'] <= 1 && $bestScore['opp_uncovered'] === 0) {
            break;
        }
    }

    return ['rounds' => $rounds, 'schedule' => $best, 'meta' => $bestScore];
}

function buildOne(int $P, int $C, int $rounds, int $playPerRound, int $matchesPerRound): ?array
{
    $games = array_fill(0, $P, 0);       // сколько сыграла пара
    $consec = array_fill(0, $P, 0);      // подряд отдыхает
    $opp = [];                            // opp[a][b] count
    $schedule = [];

    for ($rd = 0; $rd < $rounds; $rd++) {
        // Выбор играющих: меньше всех сыгравшие + дольше отдыхавшие играют.
        $order = range(0, $P - 1);
        usort($order, function ($a, $b) use ($games, $consec) {
            // дольше отдыхает -> играет раньше; меньше сыграл -> раньше
            if ($consec[$a] !== $consec[$b]) return $consec[$b] <=> $consec[$a];
            if ($games[$a] !== $games[$b]) return $games[$a] <=> $games[$b];
            return mt_rand(-1, 1);
        });
        $playing = array_slice($order, 0, $playPerRound);
        $resting = array_slice($order, $playPerRound);

        // Паросочетание играющих: минимизируем повторы соперников (жадно + рандом).
        $matches = pairUp($playing, $opp);
        if ($matches === null) return null;

        // обновления
        foreach ($playing as $p) { $games[$p]++; $consec[$p] = 0; }
        foreach ($resting as $p) { $consec[$p]++; }
        foreach ($matches as [$a, $b]) {
            $opp[$a][$b] = ($opp[$a][$b] ?? 0) + 1;
            $opp[$b][$a] = ($opp[$b][$a] ?? 0) + 1;
        }

        $schedule[] = [
            'courts' => array_map(fn($m) => [$m[0], $m[1]], $matches),
            'byes' => array_values($resting),
        ];
    }

    return $schedule;
}

function pairUp(array $players, array $opp): ?array
{
    // Рандомизированный перебор паросочетаний, берём минимум повторов.
    $best = null; $bestCost = PHP_INT_MAX;
    for ($t = 0; $t < 60; $t++) {
        $pl = $players;
        shuffle($pl);
        $matches = [];
        $cost = 0;
        for ($i = 0; $i < count($pl); $i += 2) {
            $a = $pl[$i]; $b = $pl[$i + 1];
            $cost += ($opp[$a][$b] ?? 0);
            $matches[] = [$a, $b];
        }
        if ($cost < $bestCost) { $bestCost = $cost; $best = $matches; if ($cost === 0) break; }
    }
    return $best;
}

function scoreSched(array $sched, int $P, int $rounds): array
{
    $games = array_fill(0, $P, 0);
    $byes = array_fill(0, $P, 0);
    $maxConsec = 0;
    $opp = [];
    foreach ($sched as $rd) {
        $playingSet = [];
        foreach ($rd['courts'] as [$a, $b]) {
            $opp[$a][$b] = ($opp[$a][$b] ?? 0) + 1;
            $opp[$b][$a] = ($opp[$b][$a] ?? 0) + 1;
            $games[$a]++; $games[$b]++;
            $playingSet[$a] = $playingSet[$b] = true;
        }
        foreach ($rd['byes'] as $p) $byes[$p]++;
    }
    // max consecutive byes
    foreach (range(0, $P - 1) as $p) {
        $c = 0;
        foreach ($sched as $rd) {
            if (in_array($p, $rd['byes'], true)) { $c++; $maxConsec = max($maxConsec, $c); }
            else $c = 0;
        }
    }
    $byeSpread = max($byes) - min($byes);
    // uncovered opponent pairs
    $uncovered = 0;
    for ($a = 0; $a < $P; $a++) for ($b = $a + 1; $b < $P; $b++) if (!isset($opp[$a][$b])) $uncovered++;
    return [
        'bye_spread' => $byeSpread,
        'max_consec' => $maxConsec,
        'opp_uncovered' => $uncovered,
    ];
}

function cmpScore(array $a, array $b): int
{
    // приоритет: ровный отдых -> минимум подряд -> покрытие соперников
    if ($a['bye_spread'] !== $b['bye_spread']) return $a['bye_spread'] <=> $b['bye_spread'];
    if ($a['max_consec'] !== $b['max_consec']) return $a['max_consec'] <=> $b['max_consec'];
    return $a['opp_uncovered'] <=> $b['opp_uncovered'];
}

$out = [];
foreach ($configs as $key) {
    [$P, $C] = array_map('intval', explode('-', $key));
    $res = genSchedule($P, $C);
    if (empty($res['schedule'])) continue;
    $out[$key] = [
        'pairs' => $P,
        'courts' => $C,
        'rounds' => $res['rounds'],
        'meta' => $res['meta'],
        'schedule' => $res['schedule'],
    ];
    printf("%s: rounds=%d spread=%d consec=%d uncov=%d\n",
        $key, $res['rounds'], $res['meta']['bye_spread'], $res['meta']['max_consec'], $res['meta']['opp_uncovered']);
}

$path = __DIR__ . '/database/data/americano_flex_paired_schedules.json';
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Written: $path (" . count($out) . " configs)\n";
