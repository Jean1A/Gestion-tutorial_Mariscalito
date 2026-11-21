<?php

namespace App\Http\Controllers;

use App\Http\Requests\CoordinationFilterRequest;
use App\Http\Requests\SaveTutorAssignmentRequest;
use App\Models\Section;
use App\Models\User;
use App\Services\AuditService;
use App\Services\TutorCoordinationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TutorCoordinationController extends Controller
{
    public function home(TutorCoordinationService $service): View
    {
        Gate::authorize('coordinate-tutors');

        return $this->dashboard($service, $service->filters([]));
    }

    public function index(CoordinationFilterRequest $request, TutorCoordinationService $service): View
    {
        return $this->dashboard($service, $service->filters($request->validated()));
    }

    public function tutors(CoordinationFilterRequest $request, TutorCoordinationService $service): View
    {
        $filters = $service->filters($request->validated());
        app(AuditService::class)->record('coordination.tutors_view', $request->user());

        return view('coordination.tutors', $service->options($filters) + ['tutorRows' => $service->tutorRows($filters)]);
    }

    public function tutor(CoordinationFilterRequest $request, User $tutor, TutorCoordinationService $service): View
    {
        abort_unless($tutor->roles()->where('name', 'tutor')->exists(), 404);
        $filters = $service->filters(array_replace($request->validated(), ['tutor_id' => $tutor->id, 'account_status' => 'all', 'q' => '']));
        app(AuditService::class)->record('coordination.tutor_view', $tutor);

        return $this->dashboard($service, $filters, $tutor);
    }

    public function assignments(CoordinationFilterRequest $request, TutorCoordinationService $service): View
    {
        $filters = $service->filters($request->validated());

        return view('coordination.assignments', $service->options($filters) + ['sections' => $service->assignmentSections($filters)]);
    }

    public function saveAssignment(SaveTutorAssignmentRequest $request, Section $section, TutorCoordinationService $service): RedirectResponse
    {
        $service->saveAssignment($section, $request->validated(), $request->user());

        return to_route('coordination.assignments', ['school_year_id' => $section->school_year_id])->with('status', 'Asignación guardada. El historial se conserva y los accesos del tutor se actualizan de inmediato.');
    }

    public function activities(CoordinationFilterRequest $request, TutorCoordinationService $service): View
    {
        $filters = $service->filters($request->validated());
        app(AuditService::class)->record('coordination.activities_view', $request->user());

        return view('coordination.activities', $service->options($filters) + [
            'activities' => $service->activities($filters)->paginate(20)->withQueryString(),
            'sessions' => $service->sessions($filters)->with('offering.course', 'offering.section.grade', 'schoolDay', 'recorder')->join('school_days', 'school_days.id', '=', 'course_attendance_sessions.school_day_id')->select('course_attendance_sessions.*')->withCount('records')->orderByDesc('school_days.date')->orderByDesc('course_attendance_sessions.id')->paginate(10, ['course_attendance_sessions.*'], 'sessions_page')->withQueryString(),
            'communications' => $service->during($service->communications($filters), 'created_at', $filters)->with('author')->withCount(['recipients', 'recipients as read_count' => fn ($query) => $query->whereNotNull('read_at'), 'recipients as responses_count' => fn ($query) => $query->whereNotNull('responded_at')])->latest()->paginate(10, ['*'], 'messages_page')->withQueryString(),
        ]);
    }

    public function followUp(CoordinationFilterRequest $request, TutorCoordinationService $service): View
    {
        $filters = $service->filters($request->validated());
        app(AuditService::class)->record('coordination.follow_up_view', $request->user());

        return view('coordination.follow-up', $service->options($filters) + [
            'requests' => $service->justifications($filters)->select('id', 'course_attendance_record_id', 'status', 'reviewed_at', 'created_at')->with('record.enrollment.student', 'record.session.offering.course', 'record.session.offering.section.grade')->when($filters['status'] !== 'all', fn ($query) => $query->where('status', $filters['status']))->oldest()->paginate(15)->withQueryString(),
            'alerts' => $service->alertRows($filters),
        ]);
    }

    private function dashboard(TutorCoordinationService $service, array $filters, ?User $tutor = null): View
    {
        app(AuditService::class)->record('coordination.dashboard_view', auth()->user());

        return view('coordination.home', $service->options($filters) + $service->dashboard($filters) + ['profileTutor' => $tutor]);
    }
}
