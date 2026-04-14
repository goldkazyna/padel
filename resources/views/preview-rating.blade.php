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
  }
  .header {
    margin-bottom: 20px;
  }
  .header h1 {
    font-size: 20px;
    font-weight: 700;
    color: #fff;
    margin-bottom: 4px;
  }
  .header .subtitle {
    font-size: 13px;
    color: #888;
  }
  .group {
    margin-bottom: 16px;
  }
  .group-title {
    font-size: 14px;
    font-weight: 700;
    color: #22c55e;
    padding: 8px 12px;
    background: rgba(34,197,94,.1);
    border-radius: 8px;
    margin-bottom: 8px;
    letter-spacing: .3px;
  }
  .player-card {
    background: #151517;
    border: 1px solid #1f1f23;
    border-radius: 10px;
    padding: 12px 14px;
    margin-bottom: 6px;
  }
  .player-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
  }
  .player-name {
    font-size: 15px;
    font-weight: 600;
    color: #fff;
  }
  .player-diff {
    font-size: 14px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 6px;
  }
  .player-diff.positive {
    color: #22c55e;
    background: rgba(34,197,94,.12);
  }
  .player-diff.negative {
    color: #ef4444;
    background: rgba(239,68,68,.12);
  }
  .player-diff.zero {
    color: #888;
    background: rgba(136,136,136,.12);
  }
  .player-rating {
    font-size: 13px;
    color: #888;
  }
  .player-rating span {
    color: #ccc;
    font-weight: 500;
  }
  .player-matches {
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #1f1f23;
  }
  .match-item {
    font-size: 12px;
    color: #666;
    padding: 2px 0;
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
          $class = $diff > 0 ? 'positive' : ($diff < 0 ? 'negative' : 'zero');
        @endphp
        <div class="player-card">
          <div class="player-top">
            <div class="player-name">{{ $data['name'] }}</div>
            <div class="player-diff {{ $class }}">{{ $sign }}{{ $diff }}</div>
          </div>
          <div class="player-rating">
            <span>{{ $data['rating_before'] }}</span> → <span>{{ $data['current_rating'] }}</span>
          </div>
          @if(!empty($data['matches']))
            <div class="player-matches">
              @if(is_array($data['matches']) && !is_string(reset($data['matches'])))
                @foreach($data['matches'] as $matchInfo)
                  <div class="match-item">{{ $matchInfo }}</div>
                @endforeach
              @else
                @foreach($data['matches'] as $matchInfo)
                  <div class="match-item">{{ $matchInfo }}</div>
                @endforeach
              @endif
            </div>
          @endif
        </div>
      @endforeach
    </div>
  @endforeach
</body>
</html>
