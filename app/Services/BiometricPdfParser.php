<?php

namespace App\Services;

use Carbon\Carbon;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Extracts attendance rows from biometric DTR PDFs. Used by the HR and Admin
 * biometric uploads and by the Extraction Validation page, so validation
 * measures exactly the parser that imports real attendance.
 *
 * smalot/pdfparser only returns the PDF text; the format parsers below find
 * the attendance rows in that text. They are tried in order and the first
 * one that finds rows wins.
 */
class BiometricPdfParser
{
    public const FORMAT_LABELS = [
        'civil_service' => 'Civil Service Form 48 (Daily Time Record)',
        'nu_lipa' => 'NU Lipa Timesheet Report',
        'tabular' => 'Tabular report',
        'block' => 'Block report',
        'generic' => 'Generic fallback',
        'none' => 'No supported format detected',
    ];

    private ?array $currentParseEmployee = null;

    /**
     * @return array{records: array<int, array{employee_id:string, name:string, date:string, time_in:?string, time_out:?string}>, format:string, format_label:string, pages:int, seconds:float}
     */
    public function parseFile(string $path): array
    {
        $started = microtime(true);
        $pdf = (new PdfParser())->parseFile($path);
        $result = $this->parseText($pdf->getText());

        return [
            ...$result,
            'pages' => count($pdf->getPages()),
            'seconds' => round(microtime(true) - $started, 2),
        ];
    }

    /**
     * @return array{records: array<int, array{employee_id:string, name:string, date:string, time_in:?string, time_out:?string}>, format:string, format_label:string}
     */
    public function parseText(string $text): array
    {
        $lines = preg_split('/\r?\n/', $text);
        $lines = array_map('trim', $lines);
        $lines = array_values(array_filter($lines, fn ($line) => $line !== ''));
        $this->currentParseEmployee = null;

        $parsers = [
            'civil_service' => fn () => $this->parseCivilServiceDtrFormat($lines),
            'nu_lipa' => fn () => $this->parseNuLipaFormat($lines),
            'tabular' => fn () => $this->parseTabularFormat($lines),
            'block' => fn () => $this->parseBlockFormat($lines),
            'generic' => fn () => $this->parseGenericFormat($text),
        ];

        foreach ($parsers as $format => $parse) {
            $records = $parse();

            if (! empty($records)) {
                return ['records' => $records, 'format' => $format, 'format_label' => self::FORMAT_LABELS[$format]];
            }
        }

        return ['records' => [], 'format' => 'none', 'format_label' => self::FORMAT_LABELS['none']];
    }

    /**
     * Parse Civil Service Form No. 48 / Daily Time Record exports.
     *
     * These PDFs identify an employee in the page header and use only the
     * day number in each attendance row, so the month and year come from the
     * "For the month of" header.
     */
    private function parseCivilServiceDtrFormat(array $lines): array
    {
        $records = [];
        $employeeName = null;
        $nameCandidate = null;
        $month = null;
        $year = null;
        $seen = [];

        foreach ($lines as $line) {
            if (preg_match('/^[A-Za-z][A-Za-z\' .-]+,\s*[A-Za-z][A-Za-z\' .-]+$/', $line)) {
                $nameCandidate = $line;
            }

            if (strcasecmp($line, 'For the month of') === 0) {
                $employeeName = $nameCandidate;
                continue;
            }

            if (preg_match('/^(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})$/i', $line, $matches)) {
                $month = Carbon::parse($matches[1])->month;
                $year = (int) $matches[2];
                continue;
            }

            if (! $employeeName || ! $month || ! $year) {
                continue;
            }

            if (! preg_match('/(?:am|pm)\s*(?:\d{1,2}:\d{2}\s*(?:am|pm)\s*)*([1-9]|[12]\d|3[01])(?:\s|$)/i', $line, $dayMatch, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $day = (int) $dayMatch[1][0];
            $rowPrefix = substr($line, 0, (int) $dayMatch[1][1]);
            preg_match_all('/\d{1,2}:\d{2}\s*(?:am|pm)/i', $rowPrefix, $timeMatches);

            if (empty($timeMatches[0])) {
                continue;
            }

            try {
                $date = Carbon::createFromDate($year, $month, $day)->toDateString();
            } catch (\Throwable) {
                continue;
            }

            $times = $timeMatches[0];
            usort($times, fn (string $a, string $b): int => strtotime($this->normalizeTime($a)) <=> strtotime($this->normalizeTime($b)));
            $key = $employeeName.'|'.$date;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $records[] = [
                'employee_id' => '',
                'name' => $employeeName,
                'date' => $date,
                'time_in' => $this->normalizeTime($times[0]),
                'time_out' => count($times) > 1 ? $this->normalizeTime(end($times)) : null,
            ];
        }

        return $records;
    }

    /**
     * Parser for the NU LIPA / Mustard Seed "TIMESHEET REPORT" biometric PDF.
     * Supports classic date-first rows and "Actual Break and Hours Worked"
     * exports where PDF text puts totals first and the date at the end.
     */
    private function parseNuLipaFormat(array $lines): array
    {
        $records = [];
        $currentEmployee = null;

        foreach ($lines as $line) {
            if (preg_match('/^Employee:\s*(.+?)\s*\(([^)]+)\)\s*$/i', $line, $matches)) {
                $currentEmployee = [
                    'id' => trim($matches[2]),
                    'name' => trim($matches[1]),
                ];
                continue;
            }

            if (! $currentEmployee) {
                continue;
            }

            if (preg_match('/^(Total:|Days\s+Present|Days\s+Absent)/i', $line)) {
                continue;
            }

            // Classic Timesheet Report: "04/07/2026 7:21 am 7:41 pm ..."
            if (preg_match('#^(\d{1,2}/\d{1,2}/\d{4})\s+(.+)$#', $line, $matches)) {
                $date = $matches[1];
                $rest = $matches[2];
            }
            // Actual Break and Hours Worked: "...12:38 pm 8:51 am08/01/2026"
            elseif (preg_match('#^(.+?)(\d{1,2}/\d{1,2}/\d{4})\s*$#', $line, $matches)) {
                $rest = $matches[1];
                $date = $matches[2];
            } else {
                continue;
            }

            if (! preg_match_all('/\d{1,2}:\d{2}\s*(?:am|pm)/i', $rest, $timeMatches)) {
                continue;
            }

            $times = $timeMatches[0];
            // Text extraction may list Out before In; use earliest/latest punches.
            usort($times, fn (string $a, string $b): int => strtotime($this->normalizeTime($a)) <=> strtotime($this->normalizeTime($b)));

            $timeIn = $times[0] ?? null;
            $timeOut = count($times) > 1 ? end($times) : null;

            $records[] = [
                'employee_id' => $currentEmployee['id'],
                'name' => $currentEmployee['name'],
                'date' => $this->normalizeDate($date),
                'time_in' => $timeIn ? $this->normalizeTime($timeIn) : null,
                'time_out' => $timeOut ? $this->normalizeTime($timeOut) : null,
            ];
        }

        return $records;
    }

    private function parseTabularFormat(array $lines): array
    {
        $records = [];

        foreach ($lines as $line) {
            if (preg_match('/(\d{4}-\d{3})\s*[|\t]\s*(.+?)\s*[|\t]\s*(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}|\d{4}[\/-]\d{1,2}[\/-]\d{1,2})\s*[|\t]\s*(\d{1,2}:\d{2}\s*(?:AM|PM)?)\s*[|\t]\s*(\d{1,2}:\d{2}\s*(?:AM|PM)?)/i', $line, $matches)) {
                $records[] = [
                    'employee_id' => $matches[1],
                    'name' => trim($matches[2]),
                    'date' => $this->normalizeDate($matches[3]),
                    'time_in' => $this->normalizeTime($matches[4]),
                    'time_out' => $this->normalizeTime($matches[5]),
                ];
                continue;
            }

            if (preg_match('/(\d{4}-\d{3})\s+([A-Za-z][A-Za-z\s,\.]+?)\s+(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}|\d{4}[\/-]\d{1,2}[\/-]\d{1,2})\s+(\d{1,2}:\d{2}\s*(?:AM|PM)?)\s+(\d{1,2}:\d{2}\s*(?:AM|PM)?)/i', $line, $matches)) {
                $records[] = [
                    'employee_id' => $matches[1],
                    'name' => trim($matches[2]),
                    'date' => $this->normalizeDate($matches[3]),
                    'time_in' => $this->normalizeTime($matches[4]),
                    'time_out' => $this->normalizeTime($matches[5]),
                ];
                continue;
            }

            if (preg_match('/(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}|\d{4}[\/-]\d{1,2}[\/-]\d{1,2})\s+(\d{1,2}:\d{2}\s*(?:AM|PM)?)\s+(\d{1,2}:\d{2}\s*(?:AM|PM)?)/i', $line, $matches)) {
                if (! empty($this->currentParseEmployee)) {
                    $records[] = [
                        'employee_id' => $this->currentParseEmployee['id'],
                        'name' => $this->currentParseEmployee['name'],
                        'date' => $this->normalizeDate($matches[1]),
                        'time_in' => $this->normalizeTime($matches[2]),
                        'time_out' => $this->normalizeTime($matches[3]),
                    ];
                }
            }

            if (preg_match('/^(?:Employee|Emp|ID)?[:\s]*(\d{4}-\d{3})\s*[:\-\s]+\s*([A-Za-z][A-Za-z\s,\.]+)/i', $line, $matches)) {
                $this->currentParseEmployee = [
                    'id' => $matches[1],
                    'name' => trim($matches[2]),
                ];
            }
        }

        return $records;
    }

    private function parseBlockFormat(array $lines): array
    {
        $records = [];
        $currentEmployee = null;

        foreach ($lines as $line) {
            if (preg_match('/(?:name|employee)\s*[:\-]\s*(?:(\d{4}-\d{3})\s*[:\-\s]*)?\s*([A-Za-z][A-Za-z\s,\.]+)/i', $line, $matches)) {
                $currentEmployee = [
                    'id' => $matches[1] ?: '',
                    'name' => trim($matches[2]),
                ];
                continue;
            }

            if ($currentEmployee && empty($currentEmployee['id']) && preg_match('/(?:id|emp[_\s]?id)\s*[:\-]\s*(\d{4}-\d{3})/i', $line, $matches)) {
                $currentEmployee['id'] = $matches[1];
                continue;
            }

            if ($currentEmployee && preg_match('/(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}|\d{4}[\/-]\d{1,2}[\/-]\d{1,2})\s+(\d{1,2}:\d{2}\s*(?:AM|PM)?)\s+(\d{1,2}:\d{2}\s*(?:AM|PM)?)/i', $line, $matches)) {
                $records[] = [
                    'employee_id' => $currentEmployee['id'],
                    'name' => $currentEmployee['name'],
                    'date' => $this->normalizeDate($matches[1]),
                    'time_in' => $this->normalizeTime($matches[2]),
                    'time_out' => $this->normalizeTime($matches[3]),
                ];
            }
        }

        return $records;
    }

    private function parseGenericFormat(string $text): array
    {
        $records = [];

        preg_match_all(
            '/(\d{4}-\d{3}).*?(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}|\d{4}[\/-]\d{1,2}[\/-]\d{1,2})\s+(\d{1,2}:\d{2}\s*(?:AM|PM)?)\s+(\d{1,2}:\d{2}\s*(?:AM|PM)?)/is',
            $text,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $records[] = [
                'employee_id' => $match[1],
                'name' => '',
                'date' => $this->normalizeDate($match[2]),
                'time_in' => $this->normalizeTime($match[3]),
                'time_out' => $this->normalizeTime($match[4]),
            ];
        }

        return $records;
    }

    public function normalizeDate(string $date): string
    {
        $date = trim($date);
        $formats = ['Y-m-d', 'm/d/Y', 'd/m/Y', 'm-d-Y', 'd-m-Y', 'm/d/y', 'd/m/y'];

        foreach ($formats as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $date);
                if ($parsed) {
                    return $parsed->format('Y-m-d');
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return Carbon::parse($date)->format('Y-m-d');
    }

    /**
     * "7:21 am" / "07:21 PM" => 24-hour "HH:MM".
     */
    public function normalizeTime(string $time): string
    {
        $time = trim($time);

        if (preg_match('/(\d{1,2}):(\d{2})\s*(AM|PM)/i', $time, $matches)) {
            $hour = (int) $matches[1];
            $minute = $matches[2];
            $period = strtoupper($matches[3]);

            if ($period === 'PM' && $hour !== 12) {
                $hour += 12;
            } elseif ($period === 'AM' && $hour === 12) {
                $hour = 0;
            }

            return sprintf('%02d:%s', $hour, $minute);
        }

        return $time;
    }
}
