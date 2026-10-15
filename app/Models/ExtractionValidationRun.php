<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of the PDF extraction validation: a biometric PDF compared against
 * a hand-encoded ground truth file.
 */
class ExtractionValidationRun extends Model
{
    protected $fillable = [
        'user_id',
        'pdf_filename',
        'ground_truth_filename',
        'format',
        'pages',
        'extraction_seconds',
        'expected_rows',
        'extracted_rows',
        'matched_rows',
        'time_in_correct',
        'time_out_correct',
        'fully_correct_rows',
        'employee_matched_rows',
        'mismatches',
    ];

    protected function casts(): array
    {
        return [
            'extraction_seconds' => 'float',
            'mismatches' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Metric => [correct, total]. Percentages are derived from these counts.
     *
     * @return array<string, array{label:string, correct:int, total:int, help:string}>
     */
    public function metrics(): array
    {
        return [
            'recall' => [
                'label' => 'Row detection rate (recall)',
                'correct' => $this->matched_rows,
                'total' => $this->expected_rows,
                'help' => 'Ground truth rows the parser found.',
            ],
            'precision' => [
                'label' => 'Extraction precision',
                'correct' => $this->matched_rows,
                'total' => $this->extracted_rows,
                'help' => 'Extracted rows that exist in the ground truth.',
            ],
            'time_in' => [
                'label' => 'Time-in accuracy',
                'correct' => $this->time_in_correct,
                'total' => $this->matched_rows,
                'help' => 'Detected rows with the correct time in.',
            ],
            'time_out' => [
                'label' => 'Time-out accuracy',
                'correct' => $this->time_out_correct,
                'total' => $this->matched_rows,
                'help' => 'Detected rows with the correct time out.',
            ],
            'fully_correct' => [
                'label' => 'Fully correct rows',
                'correct' => $this->fully_correct_rows,
                'total' => $this->expected_rows,
                'help' => 'Ground truth rows extracted with the correct date, time in and time out.',
            ],
            'employee_match' => [
                'label' => 'Employee match rate',
                'correct' => $this->employee_matched_rows,
                'total' => $this->extracted_rows,
                'help' => 'Extracted rows linked to a registered employee.',
            ],
        ];
    }

    public static function percent(int $correct, int $total): ?float
    {
        return $total > 0 ? round($correct / $total * 100, 2) : null;
    }

    public function f1Score(): ?float
    {
        $precision = self::percent($this->matched_rows, $this->extracted_rows);
        $recall = self::percent($this->matched_rows, $this->expected_rows);

        if (! $precision || ! $recall) {
            return $precision === null || $recall === null ? null : 0.0;
        }

        return round(2 * $precision * $recall / ($precision + $recall), 2);
    }

    public function falseRows(): int
    {
        return max(0, $this->extracted_rows - $this->matched_rows);
    }
}
