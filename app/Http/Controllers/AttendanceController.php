<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceFilterRequest;
use App\Http\Requests\RecordAttendanceRequest;
use App\Models\AttendanceSession;
use App\Models\Section;
use App\Services\AttendanceService;
use App\Services\AuditService;

class AttendanceController extends Controller
{
    public function edit(AttendanceFilterRequest $request, Section $section, AttendanceService $service, AuditService $audit)
    {
        $date = $request->validated('date') ?? now()->toDateString();
        $section->load('schoolYear', 'grade.educationLevel');
        $session = AttendanceSession::where('section_id', $section->id)->whereHas('schoolDay', fn ($q) => $q->whereDate('date', $date))->first();
        $roster = $service->roster($section, $date, $session)->get()->sortBy(fn ($e) => $e->student->full_name);
        $records = $session?->records()->get()->keyBy('enrollment_id') ?? collect();
        $summary = $records->countBy(fn ($record) => $record->status->value);
        foreach ($roster as $enrollment) {
            $audit->record('attendance.roster_view', $enrollment->student);
        }

        return view('attendance.edit', compact('section', 'date', 'session', 'roster', 'records', 'summary') + ['canRecord' => $request->user()->can('recordAttendance', $section)]);
    }

    public function store(RecordAttendanceRequest $request, Section $section, AttendanceService $service)
    {
        $service->record($section, $request->validated(), $request->user());

        return redirect()->route('attendance.edit', ['section' => $section, 'date' => $request->validated('date')])->with('status', 'Asistencia guardada. Los cambios quedaron auditados y las notificaciones se enviarán por cola.');
    }
}
