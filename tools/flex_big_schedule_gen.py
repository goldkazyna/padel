# -*- coding: utf-8 -*-
"""Генератор сеток Americano Flex для больших раскладов (4+ кортов).

Полный перебор разбиений тут невозможен (24 играющих — миллиарды вариантов),
поэтому: жадный набор четвёрок по штрафам + локальные улучшения обменами,
много рестартов, берём лучший результат.

Цели: ноль повторов партнёров, соперники не чаще 2 раз, отдых ровный.
"""
import json, random, sys
from itertools import combinations


def key(a, b):
    return (a, b) if a < b else (b, a)


def met(partners, opps, a, b):
    k = key(a, b)
    return partners[k] + opps[k]


def split_cost(four, partners, opps):
    """Лучшее разбиение четвёрки на две команды + его штраф.

    Помимо повторов штрафуем «знакомство»: соединять тех, кто ещё ни разу
    не пересекался, выгоднее — иначе к концу сетки остаются пары игроков,
    не встретившиеся вообще.
    """
    a, b, c, d = four
    best, best_cost = None, None
    for t1, t2 in (((a, b), (c, d)), ((a, c), (b, d)), ((a, d), (b, c))):
        cost = 1000 * partners[key(*t1)] + 1000 * partners[key(*t2)]
        for pair in (t1, t2):
            if met(partners, opps, *pair) == 0:
                cost -= 40
        for x in t1:
            for y in t2:
                n = opps[key(x, y)]
                cost += n * n
                if met(partners, opps, x, y) == 0:
                    cost -= 40
        if best_cost is None or cost < best_cost:
            best, best_cost = (t1, t2), cost
    return best, best_cost


def build_round(playing, partners, opps, rnd):
    """Жадно режем играющих на четвёрки, затем чуть улучшаем обменами."""
    pool = playing[:]
    rnd.shuffle(pool)
    courts = []
    while pool:
        seed = pool.pop(0)
        # кандидаты к seed — по сумме штрафов связки
        rest = sorted(pool, key=lambda x: (partners[key(seed, x)] * 10 + opps[key(seed, x)], rnd.random()))
        best_four, best_cost = None, None
        for trio in combinations(rest[:9], 3):
            four = (seed,) + trio
            _, cost = split_cost(four, partners, opps)
            if best_cost is None or cost < best_cost:
                best_four, best_cost = four, cost
        for p in best_four[1:]:
            pool.remove(p)
        courts.append(list(best_four))

    # локальные улучшения: меняем игроков местами между кортами
    def total_cost(cs):
        return sum(split_cost(c, partners, opps)[1] for c in cs)

    cur = total_cost(courts)
    for _ in range(400):
        i, j = rnd.randrange(len(courts)), rnd.randrange(len(courts))
        if i == j:
            continue
        pi, pj = rnd.randrange(4), rnd.randrange(4)
        courts[i][pi], courts[j][pj] = courts[j][pj], courts[i][pi]
        new = total_cost(courts)
        if new <= cur:
            cur = new
        else:
            courts[i][pi], courts[j][pj] = courts[j][pj], courts[i][pi]
    return courts


def generate(n_players, n_courts, n_rounds, seed):
    rnd = random.Random(seed)
    playing_count = n_courts * 4
    resting_count = n_players - playing_count
    partners = {key(a, b): 0 for a, b in combinations(range(n_players), 2)}
    opps = dict(partners)
    byes = [0] * n_players
    last_rest = set()
    schedule = []

    for _ in range(n_rounds):
        # отдыхают те, кто отдыхал меньше; подряд стараемся не сажать
        order = sorted(range(n_players),
                       key=lambda p: (byes[p], p in last_rest, rnd.random()))
        resting = order[:resting_count]
        playing = [p for p in range(n_players) if p not in set(resting)]

        courts = build_round(playing, partners, opps, rnd)
        round_courts = []
        for four in courts:
            (t1, t2), _ = split_cost(four, partners, opps)
            partners[key(*t1)] += 1
            partners[key(*t2)] += 1
            for x in t1:
                for y in t2:
                    opps[key(x, y)] += 1
            round_courts.append([list(t1), list(t2)])

        for p in resting:
            byes[p] += 1
        last_rest = set(resting)
        schedule.append({'courts': round_courts, 'byes': sorted(resting)})

    repeats = sum(v - 1 for v in partners.values() if v > 1)
    opp_max = max(opps.values()) if opps else 0
    spread = max(byes) - min(byes)
    never = sum(1 for a, b in combinations(range(n_players), 2)
                if partners[key(a, b)] + opps[key(a, b)] == 0)
    score = (repeats, opp_max, never, sum(1 for v in opps.values() if v > 2), spread)
    return {
        'players': n_players, 'courts': n_courts, 'rounds': n_rounds,
        'partner_repeats': repeats, 'opp_max': opp_max, 'bye_spread': spread,
        'never_met': never,
        'schedule': schedule,
    }, score


if __name__ == '__main__':
    n, c, rounds, tries = int(sys.argv[1]), int(sys.argv[2]), int(sys.argv[3]), int(sys.argv[4])
    best, best_score = None, None
    for s in range(tries):
        table, score = generate(n, c, rounds, s)
        if best_score is None or score < best_score:
            best, best_score = table, score
    print(json.dumps(best, ensure_ascii=False))
    print(f"# {n}-{c}: повторов партнёров {best['partner_repeats']}, "
          f"максимум встреч с соперником {best['opp_max']}, разброс отдыха {best['bye_spread']}, "
          f"ни разу не пересеклись {best['never_met']} пар",
          file=sys.stderr)
