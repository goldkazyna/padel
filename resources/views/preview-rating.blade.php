<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $tournament->name }} — Превью рейтинга</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    background: #0a0a0a;
    color: #f0f0f0;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    padding: 16px;
    min-height: 100vh;
    max-width: 1200px;
    margin: 0 auto;
  }
  .header { margin-bottom: 24px; }
  .header h1 { font-size: 32px; font-weight: 700; color: #fff; margin-bottom: 6px; }
  .header .subtitle { font-size: 18px; color: #888; }

  .group { margin-bottom: 20px; }
  .group-title {
    font-size: 20px; font-weight: 700; color: #22c55e;
    padding: 12px 18px;
    background: rgba(34,197,94,.08);
    border: 1px solid rgba(34,197,94,.15);
    border-radius: 10px;
    margin-bottom: 10px;
  }

  .player-card {
    background: #131315;
    border: 1px solid #1f1f23;
    border-radius: 12px;
    margin-bottom: 8px;
    overflow: hidden;
  }
  .player-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 16px;
    background: #18181b;
    border-bottom: 1px solid #1f1f23;
  }
  .player-info { flex: 1; }
  .player-name { font-size: 22px; font-weight: 700; color: #fff; }
  .player-phone { font-size: 16px; color: #555; margin-top: 3px; }
  .player-stats {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .player-rating-text {
    font-size: 18px;
    color: #888;
    text-align: right;
  }
  .player-rating-text .val { color: #ccc; font-weight: 600; }
  .player-diff {
    font-size: 22px;
    font-weight: 800;
    padding: 6px 14px;
    border-radius: 8px;
    min-width: 60px;
    text-align: center;
  }
  .player-diff.pos { color: #22c55e; background: rgba(34,197,94,.12); }
  .player-diff.neg { color: #ef4444; background: rgba(239,68,68,.12); }
  .player-diff.zero { color: #666; background: rgba(100,100,100,.1); }

  .matches-list { padding: 6px 12px 10px; }
  .match-row {
    display: flex;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #1a1a1d;
    gap: 10px;
  }
  .match-row:last-child { border-bottom: none; }
  .match-round {
    font-size: 16px;
    font-weight: 700;
    color: #555;
    min-width: 40px;
    flex-shrink: 0;
  }
  .match-teams {
    flex: 1;
    font-size: 16px;
    color: #999;
    line-height: 1.5;
  }
  .match-teams .team-rating {
    color: #555;
    font-size: 14px;
  }
  .match-score {
    font-size: 24px;
    font-weight: 800;
    min-width: 65px;
    text-align: center;
    letter-spacing: 1px;
  }
  .match-score .win { color: #22c55e; }
  .match-score .lose { color: #ef4444; }
  .match-score .draw { color: #888; }
  .match-change {
    font-size: 20px;
    font-weight: 700;
    min-width: 55px;
    text-align: right;
    flex-shrink: 0;
  }
  .match-change.pos { color: #22c55e; }
  .match-change.neg { color: #ef4444; }
  .match-change.zero { color: #555; }

  .match-meta {
    font-size: 14px;
    color: #444;
    margin-top: 3px;
  }
</style>
</head>
<body>
  <div class="header">
    <h1>{{ $tournament->name }}</h1>
    <div class="subtitle">Превью изменения рейтинга · {{ $tournament->start_date->format('d.m.Y') }}</div>
  </div>

  @foreach($preview as $groupName => $players)
    <div class="group">
      <div class="group-title">{{ $groupName }}</div>
      @foreach($players as $data)
        @php
          $diff = $data['current_rating'] - $data['rating_before'];
          $sign = $diff >= 0 ? '+' : '';
          $cls = $diff > 0 ? 'pos' : ($diff < 0 ? 'neg' : 'zero');
        @endphp
        <div class="player-card">
          <div class="player-header">
            <div class="player-info">
              <div class="player-name">{{ $data['name'] }}</div>
              @if(!empty($data['phone']))
                <div class="player-phone">{{ $data['phone'] }}</div>
              @endif
            </div>
            <div class="player-stats">
              <div class="player-rating-text">
                <span class="val">{{ $data['rating_before'] }}</span> → <span class="val">{{ $data['current_rating'] }}</span>
              </div>
              <div class="player-diff {{ $cls }}">{{ $sign }}{{ $diff }}</div>
            </div>
          </div>
          @if(!empty($data['matches']))
            <div class="matches-list">
              @foreach($data['matches'] as $matchInfo)
                @php
                  // Парсим строку матча
                  $matchStr = is_string($matchInfo) ? $matchInfo : '';
                  // Раунд
                  preg_match('/^Р(\d+):/', $matchStr, $roundMatch);
                  $roundNum = $roundMatch[1] ?? '?';
                  // Счёт
                  preg_match('/Счёт (\d+):(\d+)/', $matchStr, $scoreMatch);
                  $s1 = isset($scoreMatch[1]) ? (int)$scoreMatch[1] : 0;
                  $s2 = isset($scoreMatch[2]) ? (int)$scoreMatch[2] : 0;
                  // Дельта
                  preg_match('/\| ([+-]\d+)$/', $matchStr, $changeMatch);
                  $change = $changeMatch[1] ?? '+0';
                  $changeVal = (int)$change;
                  $changeCls = $changeVal > 0 ? 'pos' : ($changeVal < 0 ? 'neg' : 'zero');
                  // Команды: всё между "Р1: " и " | Счёт"
                  preg_match('/^Р\d+: (.+?) \| Счёт/', $matchStr, $teamsMatch);
                  $teamsStr = $teamsMatch[1] ?? $matchStr;
                  // Разделяем по vs
                  $teams = explode(' vs ', $teamsStr);
                  $team1 = $teams[0] ?? '';
                  $team2 = $teams[1] ?? '';
                  // K, M, Шанс
                  preg_match('/K=(\d+)/', $matchStr, $kMatch);
                  preg_match('/М=([\d.]+)/', $matchStr, $mMatch);
                  preg_match('/Шанс=(\d+)%/', $matchStr, $chanceMatch);
                @endphp
                <div class="match-row">
                  <div class="match-round">Р{{ $roundNum }}</div>
                  <div class="match-teams">
                    {{ $team1 }}<br>
                    <span style="color:#555">vs</span> {{ $team2 }}
                    @if(!empty($kMatch[1]))
                      <div class="match-meta">K={{ $kMatch[1] }} · М={{ $mMatch[1] ?? '?' }} · Шанс={{ $chanceMatch[1] ?? '?' }}%</div>
                    @endif
                  </div>
                  <div class="match-score">
                    <span class="{{ $s1 > $s2 ? 'win' : ($s1 < $s2 ? 'lose' : 'draw') }}">{{ $s1 }}</span>:<span class="{{ $s2 > $s1 ? 'win' : ($s2 < $s1 ? 'lose' : 'draw') }}">{{ $s2 }}</span>
                  </div>
                  <div class="match-change {{ $changeCls }}">{{ $change }}</div>
                </div>
              @endforeach
            </div>
          @endif
        </div>
      @endforeach
    </div>
  @endforeach
</body>
</html>
