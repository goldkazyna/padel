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

    public function columnFormats(): array
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

        // Имена тренеров и итоги разделов: без выделения отчёт читается
        // как сплошная простыня строк.
        foreach (($this->sheet->boldRows ?? []) as $index) {
            $styles[$index + 2] = ['font' => ['bold' => true]];
        }

        if ($this->sheet->totals !== null) {
            $lastRow = count($this->sheet->rows) + 2; // +1 header, +1 totals
            $styles[$lastRow] = ['font' => ['bold' => true]];
        }
        return $styles;
    }
}
