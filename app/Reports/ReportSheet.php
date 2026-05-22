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
