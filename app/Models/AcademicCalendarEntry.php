<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class AcademicCalendarEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'entry_type',
        'day_type',
        'event_date',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
        ];
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereDate('event_date', '>=', today());
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->entry_type) {
            'wfh_class_suspension' => 'WFH / Class Suspension',
            'holiday' => 'Holiday',
            default => 'Event',
        };
    }

    public function getBadgeClassAttribute(): string
    {
        return match ($this->entry_type) {
            'holiday' => 'bg-amber-100 text-amber-800',
            'wfh_class_suspension' => 'bg-violet-100 text-violet-800',
            default => 'bg-blue-100 text-blue-800',
        };
    }

    public function isNonWorking(): bool
    {
        return $this->day_type === 'non_working';
    }
}