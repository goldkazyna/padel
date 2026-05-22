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
