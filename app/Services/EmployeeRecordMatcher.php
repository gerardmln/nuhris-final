<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Matches an uploaded attendance/leave row to a registered employee, entirely
 * in memory. Match priority:
 *   1. Exact employee_id
 *   2. Employee ID without dashes ("23-8061" == "238061")
 *   3. Name: "Lastname, Firstname" or "Firstname Lastname"
 */
class EmployeeRecordMatcher
{
    private Collection $byExactId;

    /** @var array<string, Employee> */
    private array $byNormalizedId = [];

    /** @var array<string, array<int, Employee>> */
    private array $byNameIndex = [];

    public function __construct(Collection $employees)
    {
        $this->byExactId = $employees->keyBy('employee_id');

        foreach ($employees as $employee) {
            $this->byNormalizedId[Employee::normalizeEmployeeId($employee->employee_id)] = $employee;
            $this->byNameIndex[mb_strtolower(trim((string) $employee->last_name))][] = $employee;
        }
    }

    /**
     * @param  array{employee_id?:?string, name?:?string}  $record
     */
    public function match(array $record): ?Employee
    {
        return self::find($record, $this->byExactId, $this->byNormalizedId, $this->byNameIndex);
    }

    /**
     * @param  array{employee_id?:?string, name?:?string}  $record
     * @param  array<string, Employee>  $byNormalizedId
     * @param  array<string, array<int, Employee>>  $byNameIndex
     */
    public static function find(array $record, Collection $byExactId, array $byNormalizedId, array $byNameIndex): ?Employee
    {
        if (! empty($record['employee_id'])) {
            $exactId = trim($record['employee_id']);

            if ($byExactId->has($exactId)) {
                return $byExactId->get($exactId);
            }

            $normalizedId = Employee::normalizeEmployeeId($exactId);

            if (isset($byNormalizedId[$normalizedId])) {
                return $byNormalizedId[$normalizedId];
            }
        }

        if (empty($record['name'])) {
            return null;
        }

        $name = trim($record['name']);

        if (str_contains($name, ',')) {
            [$last, $first] = array_map('trim', explode(',', $name, 2));
            $firstWord = trim(explode(' ', $first)[0] ?? $first);
            $lastKey = mb_strtolower($last);

            foreach ($byNameIndex[$lastKey] ?? [] as $employee) {
                if (str_starts_with(mb_strtolower($employee->first_name), mb_strtolower($firstWord))) {
                    return $employee;
                }
            }

            return null;
        }

        $parts = preg_split('/\s+/', $name);

        if (count($parts) >= 2) {
            $firstName = mb_strtolower($parts[0]);
            $lastName = mb_strtolower(end($parts));

            foreach ($byNameIndex[$lastName] ?? [] as $employee) {
                if (mb_strtolower($employee->first_name) === $firstName) {
                    return $employee;
                }
            }
        }

        return null;
    }
}
