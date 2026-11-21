<?php

namespace App\Services;

use App\Models\SchoolDay;
use App\Models\SchoolYear;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CalendarService
{
    public function generate(SchoolYear $year, array $weekDays): void
    {
        DB::transaction(function () use ($year, $weekDays) {
            $year = SchoolYear::whereKey($year->id)->lockForUpdate()->firstOrFail();
            if ($year->closed_at) {
                throw ValidationException::withMessages(['school_year_id' => 'Reabre el año antes de cambiar su calendario.']);
            }
            foreach (CarbonPeriod::create($year->starts_on, $year->ends_on) as $date) {
                SchoolDay::firstOrCreate(['school_year_id' => $year->id, 'date' => $date->toDateString()], ['is_instructional' => in_array($date->dayOfWeekIso, array_map('intval', $weekDays), true)]);
            }
            app(AuditService::class)->record('calendar.generated', $year, ['week_days' => $weekDays]);
        });
    }

    public function save(array $data): void
    {
        DB::transaction(function () use ($data) {
            $year = SchoolYear::whereKey($data['school_year_id'])->lockForUpdate()->firstOrFail();
            if ($year->closed_at || $data['date'] < $year->starts_on->toDateString() || $data['date'] > $year->ends_on->toDateString()) {
                throw ValidationException::withMessages(['date' => 'La fecha debe estar dentro del año escolar.']);
            }
            $day = SchoolDay::where('school_year_id', $year->id)->whereDate('date', $data['date'])->lockForUpdate()->first();
            $before = $day?->only(['is_instructional', 'description']);
            $day ??= new SchoolDay;
            $day->fill($data)->save();
            app(AuditService::class)->record('calendar.updated', $day, ['before' => $before, 'after' => $day->only(['is_instructional', 'description'])]);
        });
    }
}
