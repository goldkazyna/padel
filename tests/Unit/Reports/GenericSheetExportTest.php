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
