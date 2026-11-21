<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\SwitchRoleRequest;
use App\Models\AttendanceAlert;
use App\Models\CourseAttendanceAlert;
use App\Services\AccessScope;
use App\Services\AdvisorPortalService;
use App\Services\AuditService;
use App\Services\TutorCoordinationService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, AccessScope $scope, AuditService $audit)
    {
        $user = $request->user();
        $role = $user->activeRole();
        abort_unless($role, 403);
        if ($role === RoleName::Student) {
            return redirect()->route('student-tutoring.home');
        }
        if ($role === RoleName::Guardian) {
            return redirect()->route('portal.children');
        }
        if ($role === RoleName::Teacher) {
            return redirect()->route('courses.mine');
        }
        $audit->record('dashboard.view', $user);
        if ($user->can('coordinate-tutors')) {
            return app(TutorCoordinationController::class)->home(app(TutorCoordinationService::class));
        }
        if ($role === RoleName::Tutor) {
            $data = app(AdvisorPortalService::class)->dashboard($user);
            foreach ($data['alerts'] as $alert) {
                $audit->record('advisor.alert_view', $alert);
            }
            foreach ($data['pendingRequests'] as $justification) {
                $audit->record('justification.view', $justification);
            }

            return view('advisor.home', $data);
        }
        $sections = $scope->sections($user)->with(['grade.educationLevel', 'schoolYear'])->where('is_active', true)->orderByDesc('school_year_id')->paginate(12);
        $alertModel = $role === RoleName::Assistant || config('school.legacy_attendance_enabled') ? AttendanceAlert::class : CourseAttendanceAlert::class;
        $alerts = $alertModel::with('enrollment.student')->where('is_active', true)->whereHas('enrollment', fn ($q) => $q->whereIn('student_id', $scope->students($user)->select('students.id'))->whereIn('section_id', $scope->sections($user)->select('sections.id')))->latest()->limit(10)->get();
        foreach ($alerts as $alert) {
            $audit->record('attendance.alert_view', $alert);
        }

        return view('dashboard', compact('role', 'sections', 'alerts'));
    }

    public function switchRole(SwitchRoleRequest $request, AuditService $audit)
    {
        $oldRole = $request->user()->activeRole()?->value;
        $request->session()->put('active_role', $request->validated('role'));
        $audit->record('auth.role_switched', $request->user(), ['before' => $oldRole, 'after' => $request->validated('role')]);

        return redirect()->route('dashboard');
    }
}
