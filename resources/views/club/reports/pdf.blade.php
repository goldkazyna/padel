{{--
    Печатная версия отчёта: тот же ReportSheet, что уходит в Excel.

    Шрифт DejaVu Sans — единственный в dompdf с кириллицей: с дефолтным
    Helvetica русские буквы превращаются в кракозябры.
--}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>{{ $sheet->title }}</title>
    <style>
        @page { margin: 18px 16px 26px; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 8px;
            color: #111;
            margin: 0;
        }

        .head { margin-bottom: 10px; }
        .head h1 { font-size: 13px; margin: 0 0 3px; }
        .head .meta { font-size: 8px; color: #555; }

        table { width: 100%; border-collapse: collapse; }

        th {
            background: #f0f0f0;
            border: 0.5px solid #bbb;
            padding: 4px 5px;
            text-align: left;
            font-size: 7.5px;
            text-transform: uppercase;
            letter-spacing: 0.2px;
        }

        td {
            border: 0.5px solid #ddd;
            padding: 3px 5px;
            vertical-align: top;
        }

        /* Полосатые строки: в длинной таблице глаз не теряет строку. */
        tr:nth-child(even) td { background: #fafafa; }

        tr.bold td { font-weight: bold; background: #f2f2f2; }
        tr.totals td { font-weight: bold; background: #e8f5ee; border-top: 1px solid #22c47a; }

        .num { text-align: right; }

        .foot {
            position: fixed;
            bottom: -16px;
            left: 0;
            right: 0;
            font-size: 7px;
            color: #888;
        }
    </style>
</head>
<body>
    <div class="head">
        <h1>{{ $sheet->title }}</h1>
        <div class="meta">
            {{ $club->name }} · период: {{ $from->format('d.m.Y') }} — {{ $to->format('d.m.Y') }}
            · сформирован {{ $generatedAt }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($sheet->headings as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($sheet->rows as $index => $row)
                <tr class="{{ in_array($index, $sheet->boldRows ?? [], true) ? 'bold' : '' }}">
                    @foreach ($row as $cell)
                        <td class="{{ is_numeric($cell) ? 'num' : '' }}">
                            {{ is_float($cell) || is_int($cell) ? number_format((float) $cell, 0, ',', ' ') : $cell }}
                        </td>
                    @endforeach
                </tr>
            @endforeach

            @if ($sheet->totals !== null)
                <tr class="totals">
                    @foreach ($sheet->totals as $cell)
                        <td class="{{ is_numeric($cell) ? 'num' : '' }}">
                            {{ is_float($cell) || is_int($cell) ? number_format((float) $cell, 0, ',', ' ') : $cell }}
                        </td>
                    @endforeach
                </tr>
            @endif
        </tbody>
    </table>

    <div class="foot">{{ $club->name }} · {{ $sheet->title }}</div>
</body>
</html>
