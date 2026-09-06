<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Validation\Validator;

class EmployeeWorkTimeConstraints
{
    /** Minutes past midnight for 11:00 PM. */
    public const RESTRICTED_START_MINUTES = 23 * 60;

    /** Minutes past midnight for 4:00 AM (inclusive). */
    public const RESTRICTED_END_MINUTES = 4 * 60;

    /** Shifts of 2 hours or less are rejected. */
    public const MIN_DURATION_MINUTES = 120;

    public static function isRestrictedTime(?string $time): bool
    {
        $minutes = self::toMinutes($time);

        if ($minutes === null) {
            return false;
        }

        return $minutes >= self::RESTRICTED_START_MINUTES
            || $minutes <= self::RESTRICTED_END_MINUTES;
    }

    public static function durationMinutes(?string $timeIn, ?string $timeOut): ?int
    {
        $in = self::toMinutes($timeIn);
        $out = self::toMinutes($timeOut);

        if ($in === null || $out === null) {
            return null;
        }

        $duration = $out - $in;

        return $duration > 0 ? $duration : null;
    }

    public static function failsMinimumDuration(?string $timeIn, ?string $timeOut): bool
    {
        $duration = self::durationMinutes($timeIn, $timeOut);

        return $duration !== null && $duration <= self::MIN_DURATION_MINUTES;
    }

    /**
     * Attach employee time-in / time-out business rules to a validator.
     *
     * @param  string  $timeInAttribute  Dot-path to the time in field
     * @param  string  $timeOutAttribute  Dot-path to the time out field
     */
    public static function applyToValidator(
        Validator $validator,
        string $timeInAttribute = 'time_in',
        string $timeOutAttribute = 'time_out',
    ): void {
        $validator->after(function (Validator $validator) use ($timeInAttribute, $timeOutAttribute): void {
            $data = $validator->getData();
            $timeIn = data_get($data, $timeInAttribute);
            $timeOut = data_get($data, $timeOutAttribute);

            if (! is_string($timeIn) || $timeIn === '') {
                return;
            }

            if (self::isRestrictedTime($timeIn)) {
                $validator->errors()->add(
                    $timeInAttribute,
                    'Time in cannot be between 11:00 PM and 4:00 AM.'
                );
            }

            if (! is_string($timeOut) || $timeOut === '') {
                return;
            }

            if (self::isRestrictedTime($timeOut)) {
                $validator->errors()->add(
                    $timeOutAttribute,
                    'Time out cannot be between 11:00 PM and 4:00 AM.'
                );
            }

            if (self::failsMinimumDuration($timeIn, $timeOut)) {
                $validator->errors()->add(
                    $timeOutAttribute,
                    'Time in and time out must be more than 2 hours apart.'
                );
            }
        });
    }

    private static function toMinutes(?string $time): ?int
    {
        if (! is_string($time) || $time === '') {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('H:i', substr($time, 0, 5));
        } catch (\Throwable) {
            return null;
        }

        if ($parsed === false) {
            return null;
        }

        return ((int) $parsed->format('H') * 60) + (int) $parsed->format('i');
    }
}
