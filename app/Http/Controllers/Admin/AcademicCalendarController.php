<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicCalendarEntry;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AcademicCalendarController extends Controller
{
    public function index(): View
    {
        $entries = AcademicCalendarEntry::query()
            ->orderByDesc('event_date')
            ->orderBy('title')
            ->paginate(12);

        $academicCalendarEntries = AcademicCalendarEntry::query()
            ->orderBy('event_date')
            ->get()
            ->map(fn (AcademicCalendarEntry $entry) => [
                'id' => $entry->id,
                'title' => $entry->title,
                'entry_type' => $entry->entry_type,
                'day_type' => $entry->day_type,
                'event_date' => $entry->event_date->toDateString(),
                'description' => $entry->description,
                'type_label' => $entry->type_label,
                'badge_class' => $entry->badge_class,
            ])
            ->values();

        return view('admin.academic-calendar.index', [
            'entries' => $entries,
            'academicCalendarEntries' => $academicCalendarEntries,
            'stats' => [
                'total' => AcademicCalendarEntry::query()->count(),
                'holidays' => AcademicCalendarEntry::query()->where('entry_type', 'holiday')->count(),
                'events' => AcademicCalendarEntry::query()->where('entry_type', 'event')->count(),
                'working' => AcademicCalendarEntry::query()->where('day_type', 'working')->count(),
                'non_working' => AcademicCalendarEntry::query()->where('day_type', 'non_working')->count(),
                'upcoming' => AcademicCalendarEntry::query()->upcoming()->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateEntry($request);

        $entry = AcademicCalendarEntry::query()->create($validated);

        app(AuditLogService::class)->record(
            'CREATE',
            'Academic Calendar',
            'Added calendar date: '.$entry->title.' ('.$entry->event_date->toDateString().').',
            'Success',
            ['entry_id' => $entry->id, 'event_date' => $entry->event_date->toDateString()]
        );

        return redirect()->route('admin.academic-calendar.index')
            ->with('success', 'Academic calendar date added successfully.');
    }

    public function update(Request $request, AcademicCalendarEntry $academicCalendarEntry): RedirectResponse
    {
        $validated = $this->validateEntry($request);

        $academicCalendarEntry->update($validated);

        app(AuditLogService::class)->record(
            'UPDATE',
            'Academic Calendar',
            'Updated calendar date: '.$academicCalendarEntry->title.' ('.$academicCalendarEntry->event_date->toDateString().').',
            'Success',
            ['entry_id' => $academicCalendarEntry->id, 'event_date' => $academicCalendarEntry->event_date->toDateString()]
        );

        return redirect()->route('admin.academic-calendar.index')
            ->with('success', 'Academic calendar date updated successfully.');
    }

    public function destroy(AcademicCalendarEntry $academicCalendarEntry): RedirectResponse
    {
        $title = $academicCalendarEntry->title;
        $eventDate = $academicCalendarEntry->event_date->toDateString();
        $entryId = $academicCalendarEntry->id;

        $academicCalendarEntry->delete();

        app(AuditLogService::class)->record(
            'DELETE',
            'Academic Calendar',
            'Deleted calendar date: '.$title.' ('.$eventDate.').',
            'Success',
            ['entry_id' => $entryId, 'event_date' => $eventDate]
        );

        return redirect()->route('admin.academic-calendar.index')
            ->with('success', 'Academic calendar date deleted successfully.');
    }

    private function validateEntry(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'entry_type' => ['required', 'in:holiday,event'],
            'day_type' => ['required', 'in:working,non_working'],
            'event_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}