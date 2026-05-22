# Club Excel Reports (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Дополнительные отчёты" page under `/club/reports/extra` that exports ~13 `.xlsx` reports (court load, client visits, coaches, finance, managers) filtered by current club and a chosen period.

**Architecture:** Data is built by per-category service classes that return a plain `ReportSheet` DTO (`title`, `headings`, `rows`, `totals`, `columnFormats`). A single `GenericSheetExport` (maatwebsite/excel) turns any `ReportSheet` into a styled sheet. A thin `AdditionalReportsController` maps a `{report}` slug to a service method and streams the download. Period parsing lives in a shared `ResolvesReportPeriod` trait.

**Tech Stack:** Laravel 12, PHP 8.2, maatwebsite/excel ^3.1 (phpoffice/phpspreadsheet), MySQL (prod) / sqlite (tests), PHPUnit.

---

## File Structure

- `app/Support/ResolvesReportPeriod.php` — trait: parse period from request → `[Carbon $from, Carbon $to, string $label]`.
- `app/Reports/ReportSheet.php` — DTO holding one sheet's data.
- `app/Exports/GenericSheetExport.php` — maatwebsite export wrapping a `ReportSheet`.
- `app/Reports/ClubLoadReportService.php` — `byHours`, `byWeekdays`, `byMonths`.
- `app/Reports/ClientsReportService.php` — `visits`.
- `app/Reports/CoachesReportService.php` — `usage`, `sessions`, `salary`.
- `app/Reports/FinanceReportService.php` — `sales`, `byDays`, `byWeeks`, `byMonths`, `debts`.
- `app/Reports/ManagersReportService.php` — `sales`.
- `app/Http/Controllers/Club/AdditionalReportsController.php` — `index`, `download`.
- `routes/web.php` — 2 routes in the existing `role:club_admin,super_admin` group.
- `resources/views/club/reports/extra.blade.php` — page with period selector + category buttons.
- `resources/views/club/reports/index.blade.php` — add a link button to the new page.
- `tests/Unit/Reports/*Test.php` — unit tests per service.
- `tests/Feature/AdditionalReportsTest.php` — controller smoke tests.

**Shared conventions (used by every service):**
- Club courts: `$courtIds = $club->courts()->pluck('id');`
- "Confirmed" filter: `->where('status', 'confirmed')`.
- Amount of a booking: `price - discount`.
- Duration in hours of a booking: `Carbon::parse($end)->floatDiffInRealHours(Carbon::parse($start))` where `$start`/`$end` are `start_time`/`end_time`; if end <= start add 24h (night slots).

---

## Task 1: Install maatwebsite/excel

**Files:**
- Modify: `composer.json`, `composer.lock`

- [ ] **Step 1: Require the package**

```bash
composer require maatwebsite/excel:"^3.1"
```
Expected: package + `phpoffice/phpspreadsheet` added to `composer.json`/`composer.lock`; `Maatwebsite\Excel\ExcelServiceProvider` auto-discovered.

- [ ] **Step 2: Verify the facade resolves**

```bash
php artisan tinker --execute="echo class_exists(\Maatwebsite\Excel\Facades\Excel::class) ? 'OK' : 'MISSING';"
```
Expected: `OK`

- [ ] **Step 3: Verify required PHP extensions are present (for prod parity)**

```bash
php -m | grep -iE "zip|xml|mbstring|gd"
```
Expected: `zip`, `xml` (libxml/dom), `mbstring` present. Note in PR description that prod must have `ext-zip`, `ext-xml`, `ext-mbstring`.

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "build: add maatwebsite/excel for club reports"
```

> **Prod deploy note (not a code step):** `vendor/` is gitignored, so prod needs `composer require maatwebsite/excel:"^3.1"`. Prod previously failed composer on an `ext-redis`↔`symfony/cache` platform conflict — install with `composer require maatwebsite/excel:"^3.1" --ignore-platform-req=ext-redis` if it recurs.

---

## Task 2: ResolvesReportPeriod trait

**Files:**
- Create: `app/Support/ResolvesReportPeriod.php`
- Test: `tests/Unit/Reports/ResolvesReportPeriodTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Reports;

use Tests\TestCase;
use App\Support\ResolvesReportPeriod;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ResolvesReportPeriodTest extends TestCase
{
    private function resolver()
    {
        return new class {
            use ResolvesReportPeriod;
            public function call(Request $r): array { return $this->parsePeriod($r); }
        };
    }

    public function test_today_preset(): void
    {
        Carbon::setTestNow('2026-05-22 10:00:00');
        [$from, $to, $label] = $this->resolver()->call(new Request(['preset' => 'today']));
        $this->assertEquals('2026-05-22', $from->toDateString());
        $this->assertEquals('2026-05-22', $to->toDateString());
        $this->assertEquals('Сегодня', $label);
        Carbon::setTestNow();
    }

    public function test_custom_range(): void
    {
        [$from, $to, $label] = $this->resolver()->call(new Request(['from' => '2026-05-01', 'to' => '2026-05-10']));
        $this->assertEquals('2026-05-01', $from->toDateString());
        $this->assertEquals('2026-05-10', $to->toDateString());
        $this->assertStringContainsString('01.05.2026', $label);
    }

    public function test_default_is_last_30_days(): void
    {
        Carbon::setTestNow('2026-05-22 10:00:00');
        [$from, $to] = $this->resolver()->call(new Request());
        $this->assertEquals('2026-04-23', $from->toDateString());
        $this->assertEquals('2026-05-22', $to->toDateString());
        Carbon::setTestNow();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ResolvesReportPeriodTest`
Expected: FAIL — `Class "App\Support\ResolvesReportPeriod" not found`.

- [ ] **Step 3: Implement the trait**

```php
<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;

trait ResolvesReportPeriod
{
    /** @return array{0: Carbon, 1: Carbon, 2: string} [from, to, label] */
    protected function parsePeriod(Request $request): array
    {
        $now = Carbon::today();

        switch ($request->get('preset')) {
            case 'today':
                return [$now->copy(), $now->copy(), 'Сегодня'];
            case 'week':
                return [$now->copy()->startOfWeek(), $now->copy()->endOfWeek(), 'Эта неделя'];
            case 'month':
                return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'Этот месяц'];
            case 'prev_month':
                $prev = $now->copy()->subMonthNoOverflow();
                return [$prev->copy()->startOfMonth(), $prev->copy()->endOfMonth(), 'Прошлый месяц'];
        }

        $from = $request->filled('from') ? Carbon::parse($request->get('from')) : $now->copy()->subDays(29);
        $to = $request->filled('to') ? Carbon::parse($request->get('to')) : $now->copy();
        return [$from->startOfDay(), $to->endOfDay(), $from->format('d.m.Y') . ' — ' . $to->format('d.m.Y')];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ResolvesReportPeriodTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/ResolvesReportPeriod.php tests/Unit/Reports/ResolvesReportPeriodTest.php
git commit -m "feat(reports): period-parsing trait"
```

---

## Task 3: ReportSheet DTO + GenericSheetExport

**Files:**
- Create: `app/Reports/ReportSheet.php`
- Create: `app/Exports/GenericSheetExport.php`
- Test: `tests/Unit/Reports/GenericSheetExportTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Reports;

use Tests\TestCase;
use App\Reports\ReportSheet;
use App\Exports\GenericSheetExport;

class GenericSheetExportTest extends TestCase
{
    public function test_array_includes_rows_and_totals(): void
    {
        $sheet = new ReportSheet(
            title: 'Тест',
            headings: ['A', 'B'],
            rows: [['x', 1], ['y', 2]],
            totals: ['Итого', 3],
        );
        $export = new GenericSheetExport($sheet);

        $this->assertSame(['A', 'B'], $export->headings());
        $this->assertSame([['x', 1], ['y', 2], ['Итого', 3]], $export->array());
        $this->assertSame('Тест', $export->title());
    }

    public function test_title_truncated_to_31_chars(): void
    {
        $sheet = new ReportSheet(title: str_repeat('я', 40), headings: ['A'], rows: []);
        $this->assertSame(31, mb_strlen((new GenericSheetExport($sheet))->title()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GenericSheetExportTest`
Expected: FAIL — `Class "App\Reports\ReportSheet" not found`.

- [ ] **Step 3: Implement ReportSheet DTO**

```php
<?php

namespace App\Reports;

class ReportSheet
{
    /**
     * @param string $title       Sheet tab title (also basis for filename).
     * @param string[] $headings  Column headers.
     * @param array[] $rows        Data rows (each an ordered array of cell values).
     * @param array|null $totals   Optional totals row (ordered like headings); bolded.
     * @param array<int,string>|null $columnFormats  index => Excel number format (e.g. '#,##0', '0%').
     */
    public function __construct(
        public string $title,
        public array $headings,
        public array $rows,
        public ?array $totals = null,
        public ?array $columnFormats = null,
    ) {}
}
```

- [ ] **Step 4: Implement GenericSheetExport**

```php
<?php

namespace App\Exports;

use App\Reports\ReportSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class GenericSheetExport implements FromArray, WithHeadings, WithTitle, WithStyles, ShouldAutoSize, WithColumnFormatting
{
    public function __construct(private ReportSheet $sheet) {}

    public function array(): array
    {
        $rows = $this->sheet->rows;
        if ($this->sheet->totals !== null) {
            $rows[] = $this->sheet->totals;
        }
        return $rows;
    }

    public function headings(): array
    {
        return $this->sheet->headings;
    }

    public function title(): string
    {
        return mb_substr($this->sheet->title, 0, 31);
    }

    public function columnFormatting(): array
    {
        $map = [];
        foreach (($this->sheet->columnFormats ?? []) as $index => $format) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $map[$letter] = $format;
        }
        return $map;
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [1 => ['font' => ['bold' => true]]]; // header row
        if ($this->sheet->totals !== null) {
            $lastRow = count($this->sheet->rows) + 2; // +1 header, +1 totals
            $styles[$lastRow] = ['font' => ['bold' => true]];
        }
        return $styles;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=GenericSheetExportTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Reports/ReportSheet.php app/Exports/GenericSheetExport.php tests/Unit/Reports/GenericSheetExportTest.php
git commit -m "feat(reports): ReportSheet DTO + generic xlsx export"
```

---

## Task 4: ClubLoadReportService (load by hours / weekdays / months)

**Files:**
- Create: `app/Reports/ClubLoadReportService.php`
- Test: `tests/Unit/Reports/ClubLoadReportServiceTest.php`

**Definitions:** A booking's hours = duration via `floatDiffInRealHours`. Available hours over the period, per active court = `(close-open) hours × number_of_days` minus blocked hours from `court_blocks` in range. Load% = occupied/available (0 if available is 0).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Reports;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Reports\ClubLoadReportService;
use App\Reports\ReportSheet;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClubLoadReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private Court $court;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::create(['name' => 'C', 'address' => 'A']);
        $this->court = Court::create([
            'club_id' => $this->club->id, 'name' => 'K1',
            'open_time' => '08:00:00', 'close_time' => '10:00:00', 'slot_duration' => 60,
        ]);
        // 1 confirmed booking 08:00-09:00 on 2026-05-01 (Friday)
        CourtBooking::create([
            'court_id' => $this->court->id, 'date' => '2026-05-01',
            'start_time' => '08:00:00', 'end_time' => '09:00:00',
            'price' => 3000, 'status' => 'confirmed', 'is_paid' => true,
        ]);
        // cancelled booking must be ignored
        CourtBooking::create([
            'court_id' => $this->court->id, 'date' => '2026-05-01',
            'start_time' => '09:00:00', 'end_time' => '10:00:00',
            'price' => 3000, 'status' => 'cancelled',
        ]);
    }

    public function test_by_hours_returns_sheet_with_occupied_hour(): void
    {
        $svc = new ClubLoadReportService();
        $sheet = $svc->byHours($this->club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'));
        $this->assertInstanceOf(ReportSheet::class, $sheet);
        // hour 08:00 row: occupied 1h, available 1 court * 1 day = 1h, load 100%
        $row08 = collect($sheet->rows)->firstWhere(0, '08:00');
        $this->assertEquals(1.0, $row08[1]);   // occupied
        $this->assertEquals(1.0, $row08[2]);   // available
        $this->assertEquals(1.0, $row08[3]);   // load fraction (formatted as 0%)
    }

    public function test_by_weekdays_groups_friday(): void
    {
        $svc = new ClubLoadReportService();
        $sheet = $svc->byWeekdays($this->club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'));
        $friday = collect($sheet->rows)->firstWhere(0, 'Пт');
        $this->assertEquals(1.0, $friday[1]); // occupied hours
        $this->assertEquals(1, $friday[4]);   // bookings count
    }

    public function test_by_months_groups_may(): void
    {
        $svc = new ClubLoadReportService();
        $sheet = $svc->byMonths($this->club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $may = collect($sheet->rows)->firstWhere(0, '05.2026');
        $this->assertEquals(1.0, $may[1]);
        $this->assertEquals(1, $may[4]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ClubLoadReportServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Reports;

use App\Models\Club;
use App\Models\CourtBooking;
use App\Models\CourtBlock;
use Carbon\Carbon;

class ClubLoadReportService
{
    private const WEEKDAYS = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];

    private function hoursBetween(string $start, string $end): float
    {
        $s = Carbon::parse($start);
        $e = Carbon::parse($end);
        if ($e->lessThanOrEqualTo($s)) $e->addDay();
        return $e->floatDiffInRealHours($s);
    }

    /** Confirmed bookings of the club in [from,to]. */
    private function bookings(Club $club, Carbon $from, Carbon $to)
    {
        return CourtBooking::whereIn('court_id', $club->courts()->pluck('id'))
            ->where('status', 'confirmed')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();
    }

    public function byHours(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $courts = $club->courts()->where('is_active', true)->get();
        $days = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $bookings = $this->bookings($club, $from, $to);

        $minOpen = 23; $maxClose = 0;
        foreach ($courts as $c) {
            $minOpen = min($minOpen, (int) Carbon::parse($c->open_time)->hour);
            $closeH = (int) Carbon::parse($c->close_time)->hour;
            $maxClose = max($maxClose, $closeH === 0 ? 24 : $closeH);
        }
        if ($courts->isEmpty()) { $minOpen = 8; $maxClose = 22; }

        $rows = [];
        $totOcc = 0; $totAvail = 0;
        for ($h = $minOpen; $h < $maxClose; $h++) {
            // available: courts open during [h,h+1) * days
            $availCourts = $courts->filter(function ($c) use ($h) {
                $o = (int) Carbon::parse($c->open_time)->hour;
                $cl = (int) Carbon::parse($c->close_time)->hour; $cl = $cl === 0 ? 24 : $cl;
                return $h >= $o && $h < $cl;
            })->count();
            $avail = $availCourts * $days;

            // occupied: overlap of each booking with [h,h+1)
            $occ = 0.0;
            foreach ($bookings as $b) {
                $bs = (float) Carbon::parse($b->start_time)->hour + Carbon::parse($b->start_time)->minute / 60;
                $beH = Carbon::parse($b->end_time);
                $be = (float) $beH->hour + $beH->minute / 60;
                if ($be <= $bs) $be += 24;
                $overlap = max(0, min($be, $h + 1) - max($bs, $h));
                $occ += $overlap;
            }

            $rows[] = [sprintf('%02d:00', $h), round($occ, 2), round($avail, 2), $avail > 0 ? round($occ / $avail, 4) : 0];
            $totOcc += $occ; $totAvail += $avail;
        }

        return new ReportSheet(
            title: 'Загрузка по часам',
            headings: ['Час', 'Занято, ч', 'Доступно, ч', 'Загрузка'],
            rows: $rows,
            totals: ['Итого', round($totOcc, 2), round($totAvail, 2), $totAvail > 0 ? round($totOcc / $totAvail, 4) : 0],
            columnFormats: [1 => '#,##0.0', 2 => '#,##0.0', 3 => '0%'],
        );
    }

    public function byWeekdays(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->bookings($club, $from, $to);
        $occ = array_fill(1, 7, 0.0); $cnt = array_fill(1, 7, 0);
        foreach ($bookings as $b) {
            $dow = Carbon::parse($b->date)->isoWeekday();
            $occ[$dow] += $this->hoursBetween($b->start_time, $b->end_time);
            $cnt[$dow]++;
        }
        $rows = []; $totOcc = 0; $totCnt = 0;
        foreach (self::WEEKDAYS as $i => $name) {
            $rows[] = [$name, round($occ[$i], 2), '', '', $cnt[$i]];
            $totOcc += $occ[$i]; $totCnt += $cnt[$i];
        }
        return new ReportSheet(
            title: 'Загрузка по дням недели',
            headings: ['День', 'Занято, ч', '', '', 'Броней'],
            rows: $rows,
            totals: ['Итого', round($totOcc, 2), '', '', $totCnt],
            columnFormats: [1 => '#,##0.0'],
        );
    }

    public function byMonths(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->bookings($club, $from, $to);
        $occ = []; $cnt = [];
        foreach ($bookings as $b) {
            $key = Carbon::parse($b->date)->format('m.Y');
            $occ[$key] = ($occ[$key] ?? 0) + $this->hoursBetween($b->start_time, $b->end_time);
            $cnt[$key] = ($cnt[$key] ?? 0) + 1;
        }
        ksort($occ);
        $rows = []; $totOcc = 0; $totCnt = 0;
        foreach ($occ as $key => $hours) {
            $rows[] = [$key, round($hours, 2), '', '', $cnt[$key]];
            $totOcc += $hours; $totCnt += $cnt[$key];
        }
        return new ReportSheet(
            title: 'Загрузка по месяцам',
            headings: ['Месяц', 'Занято, ч', '', '', 'Броней'],
            rows: $rows,
            totals: ['Итого', round($totOcc, 2), '', '', $totCnt],
            columnFormats: [1 => '#,##0.0'],
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ClubLoadReportServiceTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Reports/ClubLoadReportService.php tests/Unit/Reports/ClubLoadReportServiceTest.php
git commit -m "feat(reports): court load by hours/weekdays/months"
```

---

## Task 5: ClientsReportService (visits)

**Files:**
- Create: `app/Reports/ClientsReportService.php`
- Test: `tests/Unit/Reports/ClientsReportServiceTest.php`

**Definition:** For each `club_clients` row with a phone, count confirmed bookings whose `client_phone` matches any phone variant (digits, with/without leading 7). Sum amount and last visit date.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Reports;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\ClubClient;
use App\Reports\ClientsReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClientsReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_visits_counts_matching_bookings(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        ClubClient::create(['club_id' => $club->id, 'name' => 'Иван', 'phone' => '77011112233']);

        // matching booking stored without leading 7
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-02', 'start_time' => '08:00', 'end_time' => '09:00', 'client_phone' => '7011112233', 'price' => 5000, 'discount' => 0, 'status' => 'confirmed', 'is_paid' => true]);
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-05', 'start_time' => '08:00', 'end_time' => '09:00', 'client_phone' => '77011112233', 'price' => 5000, 'discount' => 1000, 'status' => 'confirmed', 'is_paid' => true]);

        $sheet = (new ClientsReportService())->visits($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $row = collect($sheet->rows)->firstWhere(0, 'Иван');
        $this->assertEquals(2, $row[2]);          // visits
        $this->assertEquals(9000, $row[3]);       // amount (5000 + (5000-1000))
        $this->assertEquals('05.05.2026', $row[4]); // last visit
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ClientsReportServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Reports;

use App\Models\Club;
use App\Models\ClubClient;
use App\Models\CourtBooking;
use Carbon\Carbon;

class ClientsReportService
{
    /** @return string[] phone digit variants for matching */
    private function phoneVariants(?string $phone): array
    {
        if (!$phone) return [];
        $digits = preg_replace('/\D/', '', $phone);
        $variants = [$digits];
        if (strlen($digits) === 10) $variants[] = '7' . $digits;
        elseif (strlen($digits) === 11 && $digits[0] === '7') $variants[] = substr($digits, 1);
        return array_values(array_unique($variants));
    }

    public function visits(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $courtIds = $club->courts()->pluck('id');
        $clients = ClubClient::where('club_id', $club->id)->orderBy('name')->get();

        $rows = []; $totVisits = 0; $totAmount = 0;
        foreach ($clients as $client) {
            $variants = $this->phoneVariants($client->phone);
            if (empty($variants)) continue;

            $bookings = CourtBooking::whereIn('court_id', $courtIds)
                ->where('status', 'confirmed')
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->where(function ($q) use ($variants) {
                    foreach ($variants as $v) {
                        $q->orWhereRaw("REPLACE(REPLACE(REPLACE(client_phone,'+',''),' ',''),'-','') = ?", [$v]);
                    }
                })
                ->get();

            if ($bookings->isEmpty()) continue;

            $amount = $bookings->sum(fn ($b) => (float) $b->price - (float) $b->discount);
            $last = $bookings->max('date');
            $rows[] = [
                $client->name,
                $client->phone,
                $bookings->count(),
                round($amount, 2),
                Carbon::parse($last)->format('d.m.Y'),
            ];
            $totVisits += $bookings->count();
            $totAmount += $amount;
        }

        usort($rows, fn ($a, $b) => $b[2] <=> $a[2]);

        return new ReportSheet(
            title: 'Посещения клиентов',
            headings: ['Клиент', 'Телефон', 'Визитов', 'Сумма', 'Последний визит'],
            rows: $rows,
            totals: ['Итого', '', $totVisits, round($totAmount, 2), ''],
            columnFormats: [3 => '#,##0'],
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ClientsReportServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Reports/ClientsReportService.php tests/Unit/Reports/ClientsReportServiceTest.php
git commit -m "feat(reports): client visits report"
```

---

## Task 6: CoachesReportService (usage / sessions / salary)

**Files:**
- Create: `app/Reports/CoachesReportService.php`
- Test: `tests/Unit/Reports/CoachesReportServiceTest.php`

**Definitions:** Coach = `court_bookings.coach_id`. Salary rate per booking: look up `coach_rates` for the coach's `club_coaches` row by the booking's whole-hour duration (`hours` column); if none, use `club_coaches.hourly_rate`. Amount to pay = rate × hours.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Reports;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\ClubCoach;
use App\Models\CoachRate;
use App\Models\User;
use App\Reports\CoachesReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CoachesReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_uses_coach_rate_then_hourly(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $coachUser = User::factory()->create(['first_name' => 'Тренер', 'name' => 'Тренер Один']);
        $cc = ClubCoach::create(['club_id' => $club->id, 'user_id' => $coachUser->id, 'hourly_rate' => 4000]);
        CoachRate::create(['club_coach_id' => $cc->id, 'hours' => 1, 'rate' => 5000]); // 1h => 5000

        // 1h booking with coach => uses coach_rate 5000
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-03', 'start_time' => '08:00', 'end_time' => '09:00', 'price' => 7000, 'discount' => 0, 'status' => 'confirmed', 'coach_id' => $coachUser->id, 'coach_paid' => true, 'is_paid' => true]);

        $svc = new CoachesReportService();
        $salary = $svc->salary($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $row = collect($salary->rows)->firstWhere(0, 'Тренер Один');
        $this->assertEquals(1, $row[1]);    // sessions
        $this->assertEquals(1.0, $row[2]);  // hours
        $this->assertEquals(5000, $row[3]); // to pay

        $usage = $svc->usage($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $u = collect($usage->rows)->firstWhere(0, 'Тренер Один');
        $this->assertEquals(1, $u[1]);      // sessions
        $this->assertEquals(7000, $u[3]);   // club income

        $sessions = $svc->sessions($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $this->assertCount(1, $sessions->rows);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CoachesReportServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Reports;

use App\Models\Club;
use App\Models\ClubCoach;
use App\Models\CoachRate;
use App\Models\CourtBooking;
use App\Models\User;
use Carbon\Carbon;

class CoachesReportService
{
    private function hours(string $start, string $end): float
    {
        $s = Carbon::parse($start); $e = Carbon::parse($end);
        if ($e->lessThanOrEqualTo($s)) $e->addDay();
        return round($e->floatDiffInRealHours($s), 2);
    }

    private function coachBookings(Club $club, Carbon $from, Carbon $to)
    {
        return CourtBooking::whereIn('court_id', $club->courts()->pluck('id'))
            ->where('status', 'confirmed')
            ->whereNotNull('coach_id')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with('court')
            ->orderBy('date')->orderBy('start_time')
            ->get();
    }

    private function coachName(int $userId, array &$cache): string
    {
        if (!isset($cache[$userId])) {
            $u = User::find($userId);
            $cache[$userId] = $u ? ($u->name ?: trim($u->first_name . ' ' . $u->last_name)) : "ID {$userId}";
        }
        return $cache[$userId];
    }

    public function usage(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->coachBookings($club, $from, $to);
        $names = [];
        $agg = []; // userId => [sessions, hours, income]
        foreach ($bookings as $b) {
            $id = $b->coach_id;
            $agg[$id] ??= [0, 0.0, 0.0];
            $agg[$id][0]++;
            $agg[$id][1] += $this->hours($b->start_time, $b->end_time);
            $agg[$id][2] += (float) $b->price - (float) $b->discount;
        }
        $rows = []; $tS = 0; $tH = 0; $tI = 0;
        foreach ($agg as $id => [$s, $h, $i]) {
            $rows[] = [$this->coachName($id, $names), $s, round($h, 2), round($i, 2)];
            $tS += $s; $tH += $h; $tI += $i;
        }
        usort($rows, fn ($a, $b) => $b[1] <=> $a[1]);
        return new ReportSheet(
            title: 'Использование услуг (тренеры)',
            headings: ['Тренер', 'Занятий', 'Часов', 'Доход клуба'],
            rows: $rows,
            totals: ['Итого', $tS, round($tH, 2), round($tI, 2)],
            columnFormats: [2 => '#,##0.0', 3 => '#,##0'],
        );
    }

    public function sessions(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->coachBookings($club, $from, $to);
        $names = [];
        $typeLabels = ['soft' => 'Мягкая', 'group' => 'Групповая', 'individual' => 'Индивид.', 'tournament' => 'Турнир'];
        $rows = []; $tAmount = 0;
        foreach ($bookings as $b) {
            $amount = (float) $b->price - (float) $b->discount;
            $rows[] = [
                Carbon::parse($b->date)->format('d.m.Y'),
                Carbon::parse($b->start_time)->format('H:i') . '–' . Carbon::parse($b->end_time)->format('H:i'),
                $b->court->name ?? '',
                $this->coachName($b->coach_id, $names),
                $b->client_name ?? '',
                $this->hours($b->start_time, $b->end_time),
                $typeLabels[$b->booking_type] ?? '',
                round($amount, 2),
            ];
            $tAmount += $amount;
        }
        return new ReportSheet(
            title: 'Проведённые тренировки',
            headings: ['Дата', 'Время', 'Корт', 'Тренер', 'Клиент', 'Часов', 'Тип', 'Сумма'],
            rows: $rows,
            totals: ['Итого', '', '', '', '', '', '', round($tAmount, 2)],
            columnFormats: [5 => '#,##0.0', 7 => '#,##0'],
        );
    }

    public function salary(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->coachBookings($club, $from, $to);
        $names = [];
        // preload coach profiles + rates per user
        $profiles = ClubCoach::where('club_id', $club->id)->get()->keyBy('user_id');
        $rates = CoachRate::whereIn('club_coach_id', $profiles->pluck('id'))->get()->groupBy('club_coach_id');

        $agg = []; // userId => [sessions, hours, pay]
        foreach ($bookings as $b) {
            $h = $this->hours($b->start_time, $b->end_time);
            $profile = $profiles->get($b->coach_id);
            $rate = null;
            if ($profile && isset($rates[$profile->id])) {
                $match = $rates[$profile->id]->firstWhere('hours', (int) round($h));
                $rate = $match?->rate;
            }
            if ($rate === null) $rate = $profile?->hourly_rate ?? 0;
            $pay = (float) $rate * $h;

            $id = $b->coach_id;
            $agg[$id] ??= [0, 0.0, 0.0];
            $agg[$id][0]++; $agg[$id][1] += $h; $agg[$id][2] += $pay;
        }
        $rows = []; $tS = 0; $tH = 0; $tP = 0;
        foreach ($agg as $id => [$s, $h, $p]) {
            $rows[] = [$this->coachName($id, $names), $s, round($h, 2), round($p, 2)];
            $tS += $s; $tH += $h; $tP += $p;
        }
        usort($rows, fn ($a, $b) => $b[3] <=> $a[3]);
        return new ReportSheet(
            title: 'Зарплата тренеров',
            headings: ['Тренер', 'Занятий', 'Часов', 'К начислению'],
            rows: $rows,
            totals: ['Итого', $tS, round($tH, 2), round($tP, 2)],
            columnFormats: [2 => '#,##0.0', 3 => '#,##0'],
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CoachesReportServiceTest`
Expected: PASS.

> If `CoachRate` model class name differs, check `app/Models/` — the migration `2026_03_30_174829_create_coach_rates_table.php` defines table `coach_rates` with columns `club_coach_id`, `hours`, `rate`. Use the existing model; create one only if missing (`class CoachRate extends Model { protected $fillable = ['club_coach_id','hours','rate']; }`).

- [ ] **Step 5: Commit**

```bash
git add app/Reports/CoachesReportService.php tests/Unit/Reports/CoachesReportServiceTest.php
git commit -m "feat(reports): coach usage/sessions/salary reports"
```

---

## Task 7: FinanceReportService (sales / byDays / byWeeks / byMonths / debts)

**Files:**
- Create: `app/Reports/FinanceReportService.php`
- Test: `tests/Unit/Reports/FinanceReportServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Reports;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Reports\FinanceReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FinanceReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private Club $club; private Court $court;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::create(['name' => 'C', 'address' => 'A']);
        $this->court = Court::create(['club_id' => $this->club->id, 'name' => 'K', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        CourtBooking::create(['court_id' => $this->court->id, 'date' => '2026-05-04', 'start_time' => '08:00', 'end_time' => '09:00', 'price' => 5000, 'discount' => 0, 'payment_method' => 'cash', 'is_paid' => true, 'status' => 'confirmed']);
        CourtBooking::create(['court_id' => $this->court->id, 'date' => '2026-05-04', 'start_time' => '09:00', 'end_time' => '10:00', 'price' => 6000, 'discount' => 1000, 'payment_method' => 'card', 'is_paid' => false, 'status' => 'confirmed']);
    }

    public function test_sales_lists_rows_and_totals(): void
    {
        $sheet = (new FinanceReportService())->sales($this->club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $this->assertCount(2, $sheet->rows);
        $this->assertEquals(10000, $sheet->totals[5]); // amount total (5000 + 5000)
    }

    public function test_by_days_aggregates(): void
    {
        $sheet = (new FinanceReportService())->byDays($this->club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $row = collect($sheet->rows)->firstWhere(0, '04.05.2026');
        $this->assertEquals(2, $row[1]);      // count
        $this->assertEquals(10000, $row[2]);  // sum
    }

    public function test_debts_only_unpaid(): void
    {
        $sheet = (new FinanceReportService())->debts($this->club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $this->assertCount(1, $sheet->rows);
        $this->assertEquals(5000, $sheet->totals[4]); // debt total (6000-1000)
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FinanceReportServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Reports;

use App\Models\Club;
use App\Models\CourtBooking;
use App\Models\User;
use Carbon\Carbon;

class FinanceReportService
{
    private function confirmed(Club $club, Carbon $from, Carbon $to)
    {
        return CourtBooking::whereIn('court_id', $club->courts()->pluck('id'))
            ->where('status', 'confirmed')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with('court')
            ->orderBy('date')->orderBy('start_time')
            ->get();
    }

    private function managerName(?int $id, array &$cache): string
    {
        if (!$id) return '';
        if (!isset($cache[$id])) {
            $u = User::find($id);
            $cache[$id] = $u ? ($u->name ?: trim($u->first_name . ' ' . $u->last_name)) : "ID {$id}";
        }
        return $cache[$id];
    }

    public function sales(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->confirmed($club, $from, $to);
        $names = [];
        $rows = []; $tAmount = 0; $tDiscount = 0;
        foreach ($bookings as $b) {
            $amount = (float) $b->price - (float) $b->discount;
            $rows[] = [
                Carbon::parse($b->date)->format('d.m.Y'),
                Carbon::parse($b->start_time)->format('H:i'),
                $b->court->name ?? '',
                $b->client_name ?? '',
                $b->client_phone ?? '',
                round($amount, 2),
                round((float) $b->discount, 2),
                $b->payment_method ?? '',
                $b->is_paid ? 'Да' : 'Нет',
                $this->managerName($b->booked_by, $names),
            ];
            $tAmount += $amount; $tDiscount += (float) $b->discount;
        }
        return new ReportSheet(
            title: 'Продажи',
            headings: ['Дата', 'Время', 'Корт', 'Клиент', 'Телефон', 'Сумма', 'Скидка', 'Оплата', 'Оплачено', 'Менеджер'],
            rows: $rows,
            totals: ['Итого', '', '', '', '', round($tAmount, 2), round($tDiscount, 2), '', '', ''],
            columnFormats: [5 => '#,##0', 6 => '#,##0'],
        );
    }

    /** @param callable(CourtBooking):string $keyFn  group key; @param callable(string):string $labelFn */
    private function aggregate(Club $club, Carbon $from, Carbon $to, callable $keyFn, callable $labelFn, string $title, string $colName): ReportSheet
    {
        $bookings = $this->confirmed($club, $from, $to);
        $cnt = []; $sum = [];
        foreach ($bookings as $b) {
            $k = $keyFn($b);
            $cnt[$k] = ($cnt[$k] ?? 0) + 1;
            $sum[$k] = ($sum[$k] ?? 0) + ((float) $b->price - (float) $b->discount);
        }
        ksort($cnt);
        $rows = []; $tC = 0; $tS = 0;
        foreach ($cnt as $k => $c) {
            $avg = $c > 0 ? $sum[$k] / $c : 0;
            $rows[] = [$labelFn($k), $c, round($sum[$k], 2), round($avg, 2)];
            $tC += $c; $tS += $sum[$k];
        }
        return new ReportSheet(
            title: $title,
            headings: [$colName, 'Броней', 'Сумма', 'Средний чек'],
            rows: $rows,
            totals: ['Итого', $tC, round($tS, 2), $tC > 0 ? round($tS / $tC, 2) : 0],
            columnFormats: [2 => '#,##0', 3 => '#,##0'],
        );
    }

    public function byDays(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        return $this->aggregate($club, $from, $to,
            fn ($b) => Carbon::parse($b->date)->format('Y-m-d'),
            fn ($k) => Carbon::parse($k)->format('d.m.Y'),
            'Продажи по дням', 'Дата');
    }

    public function byWeeks(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        return $this->aggregate($club, $from, $to,
            fn ($b) => Carbon::parse($b->date)->format('o-W'),
            function ($k) {
                [$year, $week] = explode('-', $k);
                $start = Carbon::now()->setISODate((int) $year, (int) $week)->startOfWeek();
                return $start->format('d.m') . '–' . $start->copy()->endOfWeek()->format('d.m.Y');
            },
            'Продажи по неделям', 'Неделя');
    }

    public function byMonths(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        return $this->aggregate($club, $from, $to,
            fn ($b) => Carbon::parse($b->date)->format('Y-m'),
            fn ($k) => Carbon::parse($k . '-01')->format('m.Y'),
            'Продажи по месяцам', 'Месяц');
    }

    public function debts(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->confirmed($club, $from, $to)->where('is_paid', false);
        $names = [];
        $rows = []; $tDebt = 0;
        foreach ($bookings as $b) {
            $amount = (float) $b->price - (float) $b->discount;
            $rows[] = [
                Carbon::parse($b->date)->format('d.m.Y'),
                $b->court->name ?? '',
                $b->client_name ?? '',
                $b->client_phone ?? '',
                round($amount, 2),
                $this->managerName($b->booked_by, $names),
            ];
            $tDebt += $amount;
        }
        return new ReportSheet(
            title: 'Задолженности',
            headings: ['Дата', 'Корт', 'Клиент', 'Телефон', 'Сумма', 'Менеджер'],
            rows: $rows,
            totals: ['Итого', '', '', '', round($tDebt, 2), ''],
            columnFormats: [4 => '#,##0'],
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=FinanceReportServiceTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Reports/FinanceReportService.php tests/Unit/Reports/FinanceReportServiceTest.php
git commit -m "feat(reports): finance sales/aggregations/debts reports"
```

---

## Task 8: ManagersReportService (sales by manager & day)

**Files:**
- Create: `app/Reports/ManagersReportService.php`
- Test: `tests/Unit/Reports/ManagersReportServiceTest.php`

**Definition:** Group confirmed bookings by `booked_by` then date (day = "смена"). One row per manager+day; amount = sum of `price-discount`, plus paid sum.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Reports;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use App\Reports\ManagersReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ManagersReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_by_manager_and_day(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $mgr = User::factory()->create(['name' => 'Менеджер А']);
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-06', 'start_time' => '08:00', 'end_time' => '09:00', 'price' => 5000, 'discount' => 0, 'is_paid' => true, 'status' => 'confirmed', 'booked_by' => $mgr->id]);
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-06', 'start_time' => '09:00', 'end_time' => '10:00', 'price' => 5000, 'discount' => 0, 'is_paid' => false, 'status' => 'confirmed', 'booked_by' => $mgr->id]);

        $sheet = (new ManagersReportService())->sales($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $row = collect($sheet->rows)->first(fn ($r) => $r[0] === 'Менеджер А' && $r[1] === '06.05.2026');
        $this->assertEquals(2, $row[2]);     // bookings
        $this->assertEquals(10000, $row[3]); // sum
        $this->assertEquals(5000, $row[4]);  // paid
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ManagersReportServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Reports;

use App\Models\Club;
use App\Models\CourtBooking;
use App\Models\User;
use Carbon\Carbon;

class ManagersReportService
{
    public function sales(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = CourtBooking::whereIn('court_id', $club->courts()->pluck('id'))
            ->where('status', 'confirmed')
            ->whereNotNull('booked_by')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('booked_by')->orderBy('date')
            ->get();

        $names = [];
        $agg = []; // "userId|date" => [count, sum, paid]
        foreach ($bookings as $b) {
            $key = $b->booked_by . '|' . Carbon::parse($b->date)->format('Y-m-d');
            $agg[$key] ??= [0, 0.0, 0.0];
            $amount = (float) $b->price - (float) $b->discount;
            $agg[$key][0]++;
            $agg[$key][1] += $amount;
            if ($b->is_paid) $agg[$key][2] += $amount;
        }
        ksort($agg);

        $rows = []; $tC = 0; $tS = 0; $tP = 0;
        foreach ($agg as $key => [$c, $s, $p]) {
            [$uid, $date] = explode('|', $key);
            if (!isset($names[$uid])) {
                $u = User::find($uid);
                $names[$uid] = $u ? ($u->name ?: trim($u->first_name . ' ' . $u->last_name)) : "ID {$uid}";
            }
            $rows[] = [$names[$uid], Carbon::parse($date)->format('d.m.Y'), $c, round($s, 2), round($p, 2)];
            $tC += $c; $tS += $s; $tP += $p;
        }

        return new ReportSheet(
            title: 'Продажи менеджеров',
            headings: ['Менеджер', 'Дата (смена)', 'Броней', 'Сумма', 'Оплачено'],
            rows: $rows,
            totals: ['Итого', '', $tC, round($tS, 2), round($tP, 2)],
            columnFormats: [3 => '#,##0', 4 => '#,##0'],
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ManagersReportServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Reports/ManagersReportService.php tests/Unit/Reports/ManagersReportServiceTest.php
git commit -m "feat(reports): manager sales report"
```

---

## Task 9: AdditionalReportsController + routes

**Files:**
- Create: `app/Http/Controllers/Club/AdditionalReportsController.php`
- Modify: `routes/web.php` (inside the `role:club_admin,super_admin` group, near lines 177-181)
- Test: `tests/Feature/AdditionalReportsTest.php`

The controller holds a `REPORTS` registry mapping slug → `[serviceClass, method, filenameBase]`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AdditionalReportsTest extends TestCase
{
    use RefreshDatabase;

    private function clubAdmin(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        Court::create(['club_id' => $club->id, 'name' => 'K', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        return [$club, $admin];
    }

    public function test_index_page_loads(): void
    {
        [, $admin] = $this->clubAdmin();
        $this->actingAs($admin)->get('/club/reports/extra')->assertOk()->assertSee('Дополнительные отчёты');
    }

    public function test_each_report_downloads_xlsx(): void
    {
        [, $admin] = $this->clubAdmin();
        $slugs = ['club-hours','club-weekdays','club-months','clients-visits','coaches-usage','coaches-sessions','coaches-salary','finance-sales','finance-days','finance-weeks','finance-months','finance-debts','managers-sales'];
        foreach ($slugs as $slug) {
            $resp = $this->actingAs($admin)->get("/club/reports/extra/{$slug}?from=2026-05-01&to=2026-05-31");
            $resp->assertOk();
            $this->assertStringContainsString('spreadsheetml', $resp->headers->get('content-type'));
        }
    }

    public function test_unknown_report_404(): void
    {
        [, $admin] = $this->clubAdmin();
        $this->actingAs($admin)->get('/club/reports/extra/nope')->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdditionalReportsTest`
Expected: FAIL — 404 on `/club/reports/extra` (route missing).

- [ ] **Step 3: Implement the controller**

```php
<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Reports\ClubLoadReportService;
use App\Reports\ClientsReportService;
use App\Reports\CoachesReportService;
use App\Reports\FinanceReportService;
use App\Reports\ManagersReportService;
use App\Exports\GenericSheetExport;
use App\Support\ResolvesReportPeriod;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;

class AdditionalReportsController extends Controller
{
    use ResolvesReportPeriod;

    /** slug => [serviceClass, method, filenameBase, categoryLabel, reportLabel] */
    private const REPORTS = [
        'club-hours'       => [ClubLoadReportService::class, 'byHours',   'zagruzka-po-chasam',     'Клуб', 'Загруженность по часам'],
        'club-weekdays'    => [ClubLoadReportService::class, 'byWeekdays','zagruzka-po-dnyam',      'Клуб', 'Загруженность по дням недели'],
        'club-months'      => [ClubLoadReportService::class, 'byMonths',  'zagruzka-po-mesyacam',   'Клуб', 'Загруженность по месяцам'],
        'clients-visits'   => [ClientsReportService::class,  'visits',    'poseshcheniya',          'Клиенты', 'Посещения клиентов'],
        'coaches-usage'    => [CoachesReportService::class,  'usage',     'trenery-ispolzovanie',   'Тренеры', 'Использование услуг'],
        'coaches-sessions' => [CoachesReportService::class,  'sessions',  'trenery-trenirovki',     'Тренеры', 'Проведённые тренировки'],
        'coaches-salary'   => [CoachesReportService::class,  'salary',    'trenery-zarplata',       'Тренеры', 'Зарплата тренеров'],
        'finance-sales'    => [FinanceReportService::class,  'sales',     'prodazhi',               'Финансы', 'Продажи'],
        'finance-days'     => [FinanceReportService::class,  'byDays',    'prodazhi-po-dnyam',      'Финансы', 'Продажи по дням'],
        'finance-weeks'    => [FinanceReportService::class,  'byWeeks',   'prodazhi-po-nedelyam',   'Финансы', 'Продажи по неделям'],
        'finance-months'   => [FinanceReportService::class,  'byMonths',  'prodazhi-po-mesyacam',   'Финансы', 'Продажи по месяцам'],
        'finance-debts'    => [FinanceReportService::class,  'debts',     'zadolzhennosti',         'Финансы', 'Задолженности'],
        'managers-sales'   => [ManagersReportService::class, 'sales',     'menedzhery-prodazhi',    'Менеджеры', 'Аналитика продаж менеджеров'],
    ];

    private function getClub(): ?Club
    {
        $user = auth()->user();
        if (!$user) return null;
        if ($user->isSuperAdmin()) return Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    public function index(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);
        [$from, $to, $periodLabel] = $this->parsePeriod($request);

        // group registry by category for the view
        $grouped = [];
        foreach (self::REPORTS as $slug => [$svc, $method, $file, $category, $label]) {
            $grouped[$category][] = ['slug' => $slug, 'label' => $label];
        }

        return view('club.reports.extra', [
            'club' => $club,
            'from' => $from,
            'to' => $to,
            'periodLabel' => $periodLabel,
            'preset' => $request->get('preset'),
            'grouped' => $grouped,
        ]);
    }

    public function download(Request $request, string $report)
    {
        $club = $this->getClub();
        if (!$club) abort(403);
        if (!isset(self::REPORTS[$report])) abort(404);

        [$serviceClass, $method, $fileBase] = self::REPORTS[$report];
        [$from, $to] = $this->parsePeriod($request);

        $sheet = app($serviceClass)->{$method}($club, $from, $to);
        $filename = $fileBase . '_' . $from->format('Y-m-d') . '_' . $to->format('Y-m-d') . '.xlsx';

        return Excel::download(new GenericSheetExport($sheet), $filename);
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, inside the `role:club_admin,super_admin` group (right after the existing `reports.noPhone` route near line 180), add:

```php
            // Дополнительные отчёты (Excel)
            Route::get('/reports/extra', [App\Http\Controllers\Club\AdditionalReportsController::class, 'index'])->name('reports.extra.index');
            Route::get('/reports/extra/{report}', [App\Http\Controllers\Club\AdditionalReportsController::class, 'download'])->name('reports.extra.download');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AdditionalReportsTest`
Expected: PASS (3 tests). The `index` test will also need the view from Task 10 — if it fails only on the view, proceed to Task 10 then re-run.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Club/AdditionalReportsController.php routes/web.php tests/Feature/AdditionalReportsTest.php
git commit -m "feat(reports): additional reports controller + routes"
```

---

## Task 10: Views — extra page + link button

**Files:**
- Create: `resources/views/club/reports/extra.blade.php`
- Modify: `resources/views/club/reports/index.blade.php` (add link near the existing export button, ~line 30)

- [ ] **Step 1: Create the extra page view**

```blade
@extends('layouts.app')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-white">Дополнительные отчёты</h1>
        <a href="{{ route('club.reports.index') }}" class="text-sm text-gray-400 hover:text-white">← К отчётам</a>
    </div>

    {{-- Period selector --}}
    <form method="GET" class="mb-6 flex flex-wrap items-end gap-2">
        <div class="flex gap-1">
            @foreach (['today' => 'Сегодня', 'week' => 'Неделя', 'month' => 'Месяц', 'prev_month' => 'Прошлый месяц'] as $key => $label)
                <a href="?preset={{ $key }}"
                   class="px-3 py-1.5 rounded text-sm {{ $preset === $key ? 'bg-emerald-600 text-white' : 'bg-gray-700 text-gray-200' }}">{{ $label }}</a>
            @endforeach
        </div>
        <input type="date" name="from" value="{{ $from->format('Y-m-d') }}" class="bg-gray-800 text-white rounded px-2 py-1.5 text-sm">
        <input type="date" name="to" value="{{ $to->format('Y-m-d') }}" class="bg-gray-800 text-white rounded px-2 py-1.5 text-sm">
        <button class="px-3 py-1.5 rounded bg-gray-600 text-white text-sm">Применить</button>
        <span class="text-sm text-gray-400 ml-2">Период: {{ $periodLabel }}</span>
    </form>

    {{-- Report categories --}}
    @foreach ($grouped as $category => $reports)
        <div class="mb-6">
            <h2 class="text-lg font-semibold text-white mb-2">{{ $category }}</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                @foreach ($reports as $r)
                    <a href="{{ route('club.reports.extra.download', $r['slug']) }}?{{ http_build_query(request()->only(['preset', 'from', 'to'])) }}"
                       class="flex items-center justify-between bg-gray-800 hover:bg-gray-700 text-white rounded px-4 py-3">
                        <span>{{ $r['label'] }}</span>
                        <span class="text-emerald-400 text-sm">Excel ↓</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
@endsection
```

> Match the actual layout: the project uses `@extends('layouts.app')`. Verify the section name (`@section('content')`) against `resources/views/club/reports/index.blade.php` and mirror whatever wrapper/section that file uses. If the dark theme uses different CSS classes, mirror those from `index.blade.php`.

- [ ] **Step 2: Add the link button on the existing reports page**

In `resources/views/club/reports/index.blade.php`, near the existing "Экспорт в Excel" button (~line 30), add alongside it:

```blade
<a href="{{ route('club.reports.extra.index') }}"
   class="inline-flex items-center px-4 py-2 rounded bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">
    Дополнительные отчёты
</a>
```

- [ ] **Step 3: Manually verify the page renders**

Run: `composer dev` (or `php artisan serve`), log in as a club admin, open `/club/reports`, click "Дополнительные отчёты", confirm the page loads with period selector and category buttons. Click one button per category and confirm an `.xlsx` downloads and opens in Excel/LibreOffice with correct Cyrillic and numbers.

- [ ] **Step 4: Run the controller feature test again**

Run: `php artisan test --filter=AdditionalReportsTest`
Expected: PASS (3 tests, including `test_index_page_loads`).

- [ ] **Step 5: Commit**

```bash
git add resources/views/club/reports/extra.blade.php resources/views/club/reports/index.blade.php
git commit -m "feat(reports): extra reports page + link button"
```

---

## Task 11: Full suite + regression

**Files:** none (verification only)

- [ ] **Step 1: Run the entire test suite**

Run: `php artisan test`
Expected: all tests pass (new report tests + existing `CourtScheduleTest`, `ProfileTest`, etc. green).

- [ ] **Step 2: Push**

```bash
git push
```

> **Prod deploy reminder:** after pull, run `composer require maatwebsite/excel:"^3.1"` (or `composer install`) on prod, handling the `ext-redis` platform conflict if it appears (`--ignore-platform-req=ext-redis`), then `php artisan optimize:clear`.

---

## Self-Review

**Spec coverage:** All 13 reports from the spec map to tasks 4-8 and the controller registry in task 9 (slugs match: club-hours/weekdays/months, clients-visits, coaches-usage/sessions/salary, finance-sales/days/weeks/months/debts, managers-sales). Period trait (task 2), DTO+export (task 3), page+button (task 10), tests throughout, deploy notes for the maatwebsite install + prod composer risk (tasks 1, 11). Phase-2 items are explicitly out of scope.

**Placeholder scan:** No TBD/TODO. Every code step contains full code. The two "match the layout" / "if model differs" notes point at concrete files to check and give the exact fallback to write — not deferred work.

**Type consistency:** `ReportSheet(title, headings, rows, totals, columnFormats)` constructor matches all `new ReportSheet(...)` calls (named args). `GenericSheetExport(ReportSheet)` matches controller usage. Service method names (`byHours/byWeekdays/byMonths/visits/usage/sessions/salary/sales/byDays/byWeeks/byMonths/debts/sales`) match the controller `REPORTS` registry method column. `parsePeriod` returns `[from, to, label]` consumed consistently. Column index→`totals` index used in tests (e.g. amount at index 5 in finance-sales) matches the headings order in the service.
