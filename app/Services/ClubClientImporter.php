<?php

namespace App\Services;

use App\Models\ClubClient;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ClubClientImporter
{
    public function import(int $clubId, string $filePath): array
    {
        $rows = $this->readRows($filePath);
        if (empty($rows)) {
            return $this->emptyReport('Файл пустой');
        }

        $headerMap = $this->detectColumns(array_shift($rows));
        if ($headerMap['name'] === null) {
            return $this->emptyReport('В файле не найдена колонка с ФИО клиента');
        }

        return $this->processRows($clubId, $rows, $headerMap);
    }

    private function readRows(string $filePath): array
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        return $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
    }

    private function detectColumns(array $header): array
    {
        $map = ['name' => null, 'card' => null, 'tags' => null, 'phone' => null, 'email' => null];
        foreach ($header as $idx => $cell) {
            $h = mb_strtolower(trim((string) $cell));
            if ($h === '') continue;
            if ($map['name'] === null && (str_contains($h, 'фио') || str_contains($h, 'имя') || str_contains($h, 'клиент'))) {
                $map['name'] = $idx;
            } elseif ($map['card'] === null && (str_contains($h, 'карт'))) {
                $map['card'] = $idx;
            } elseif ($map['tags'] === null && (str_contains($h, 'тег') || str_contains($h, 'метк'))) {
                $map['tags'] = $idx;
            } elseif ($map['phone'] === null && (str_contains($h, 'телефон') || str_contains($h, 'phone') || str_contains($h, 'тел.'))) {
                $map['phone'] = $idx;
            } elseif ($map['email'] === null && (str_contains($h, 'mail') || str_contains($h, 'почта'))) {
                $map['email'] = $idx;
            }
        }
        return $map;
    }

    private function processRows(int $clubId, array $rows, array $map): array
    {
        $created = 0;
        $updated = 0;
        $skippedNoPhone = [];
        $skippedDuplicate = [];
        $seenPhonesInFile = [];

        foreach ($rows as $rowIndex => $row) {
            $rowNum = $rowIndex + 2;

            $name = $this->cell($row, $map['name']);
            if ($name === '') continue;

            $phone = $this->normalizePhone($this->cell($row, $map['phone']));
            $email = $this->normalizeEmail($this->cell($row, $map['email']));
            $card = $this->cell($row, $map['card']);
            $tags = $this->cleanTags($this->cell($row, $map['tags']));

            if ($phone === '') {
                $skippedNoPhone[] = ['row' => $rowNum, 'name' => $name, 'email' => $email, 'card' => $card];
                continue;
            }

            if (isset($seenPhonesInFile[$phone])) {
                $skippedDuplicate[] = ['row' => $rowNum, 'name' => $name, 'phone' => $phone, 'first_row' => $seenPhonesInFile[$phone]];
                continue;
            }
            $seenPhonesInFile[$phone] = $rowNum;

            $existing = ClubClient::where('club_id', $clubId)->where('phone', $phone)->first();
            $note = $tags !== '' ? $tags : null;

            if ($existing) {
                $payload = array_filter([
                    'email' => $existing->email ?: ($email ?: null),
                    'card_number' => $existing->card_number ?: ($card ?: null),
                    'note' => $existing->note ?: $note,
                ], fn($v) => $v !== null);
                if (!empty($payload)) {
                    $existing->update($payload);
                    $updated++;
                }
            } else {
                ClubClient::create([
                    'club_id' => $clubId,
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $email ?: null,
                    'card_number' => $card !== '' ? $card : null,
                    'note' => $note,
                ]);
                $created++;
            }
        }

        return [
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'skipped_no_phone' => $skippedNoPhone,
            'skipped_duplicate' => $skippedDuplicate,
        ];
    }

    private function cell(array $row, ?int $idx): string
    {
        if ($idx === null) return '';
        return trim((string) ($row[$idx] ?? ''));
    }

    public function normalizePhone(string $raw): string
    {
        if ($raw === '') return '';
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '') return '';
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }
        return $digits;
    }

    private function normalizeEmail(string $raw): string
    {
        $email = mb_strtolower(trim($raw));
        if ($email === '') return '';
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function cleanTags(string $raw): string
    {
        $clean = trim($raw, " ,\t\n\r\0\x0B");
        return preg_replace('/\s*,\s*,+/', ', ', $clean);
    }

    private function emptyReport(string $error): array
    {
        return [
            'success' => false,
            'error' => $error,
            'created' => 0,
            'updated' => 0,
            'skipped_no_phone' => [],
            'skipped_duplicate' => [],
        ];
    }
}
