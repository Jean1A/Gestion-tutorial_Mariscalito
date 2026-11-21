<?php

namespace App\Services;

use App\Models\CourseResult;
use App\Models\SchoolYear;
use App\Models\Student;
use Illuminate\Support\Collection;

class InformativeReportService
{
    public function results(Student $student, SchoolYear $year): \Illuminate\Database\Eloquent\Collection
    {
        abort_unless($student->enrollments()->where('school_year_id', $year->id)->exists(), 404);

        return CourseResult::with('offering.course', 'offering.section.grade', 'period')->where('is_published', true)->whereHas('enrollment', fn ($q) => $q->where('student_id', $student->id)->where('school_year_id', $year->id))->get()->sortBy(fn ($r) => $r->offering->course->name.' '.$r->period->starts_on);
    }

    public function pdf(Student $student, SchoolYear $year, Collection $results): string
    {
        $lines = [config('school.name'), 'BOLETA INFORMATIVA · '.$year->year, $student->full_name, 'No reemplaza SIAGIE ni las libretas oficiales.', 'Escala: AD (destacado), A (esperado), B (en proceso), C (en inicio).', ''];
        foreach ($results as $result) {
            $lines[] = $result->offering->course->name.' | '.$result->period->name.' | '.$result->achievement;
        }
        if ($results->isEmpty()) {
            $lines[] = 'Todavía no hay niveles de logro publicados para este año.';
        }

        return app(SchoolReportService::class)->pdf($lines);
    }
}
