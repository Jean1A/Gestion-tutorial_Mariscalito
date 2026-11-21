<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\AdvisorFilterRequest;
use App\Http\Requests\ReviewJustificationRequest;
use App\Http\Requests\SubmitJustificationRequest;
use App\Models\AbsenceJustification;
use App\Models\CourseAttendanceRecord;
use App\Services\AccessScope;
use App\Services\AuditService;
use App\Services\CourseRecordService;
use App\Services\GuardianPortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JustificationController extends Controller
{
    public function create(Request $request, CourseAttendanceRecord $record, GuardianPortalService $portal, AuditService $audit): View
    {
        Gate::authorize('guardian-portal');
        $child = $portal->child($request->user(), $record->enrollment->student);
        $enrollment = $record->enrollment;
        $offering = $record->session->offering;
        $justification = $record->justification;
        abort_if($justification && $justification->user_id !== $request->user()->id, 403);
        $audit->record('justification.form_view', $record);

        return view('guardian.justify', compact('child', 'enrollment', 'offering', 'record', 'justification'));
    }

    public function store(SubmitJustificationRequest $request, CourseAttendanceRecord $record, CourseRecordService $service): RedirectResponse
    {
        $service->submit($record, $request->validated(), $request->file('document'), $request->user());

        return redirect()->route('portal.absences', ['student' => $record->enrollment->student_id, 'offering' => $record->session->course_offering_id])->with('status', 'Solicitud enviada. La falta seguirá injustificada hasta que el tutor la apruebe.');
    }

    public function index(AdvisorFilterRequest $request, AccessScope $scope, AuditService $audit): View
    {
        abort_unless($request->user()->activeRole() === RoleName::Tutor && $request->user()->permits('attendance.record'), 403);
        $status = $request->validated('status') ?? 'pending';
        $requests = AbsenceJustification::with('record.enrollment.student', 'record.session.offering.course', 'record.session.schoolDay', 'guardian')->whereHas('record.session.offering', fn ($q) => $q->whereIn('section_id', $scope->sections($request->user())->select('sections.id')))->when($status !== 'all', fn ($q) => $q->where('status', $status))->latest()->paginate(20)->withQueryString();
        foreach ($requests as $justification) {
            $audit->record('justification.view', $justification);
        }

        return view('courses.justifications', compact('requests', 'status'));
    }

    public function review(ReviewJustificationRequest $request, AbsenceJustification $justification, CourseRecordService $service): RedirectResponse
    {
        $service->review($justification, $request->validated(), $request->user());

        return back()->with('status', 'Revisión guardada con auditoría.');
    }

    public function document(AbsenceJustification $justification, AuditService $audit): StreamedResponse
    {
        Gate::authorize('view', $justification);
        abort_unless($justification->path, 404);
        $audit->record('justification.document_download', $justification);
        $extension = match ($justification->mime) {
            'application/pdf' => 'pdf','image/png' => 'png',default => 'jpg'
        };

        return Storage::disk('local')->download($justification->path, 'sustento.'.$extension, ['Content-Type' => $justification->mime, 'X-Content-Type-Options' => 'nosniff']);
    }
}
