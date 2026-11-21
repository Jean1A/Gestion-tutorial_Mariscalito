<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\StaffStudentMessage;
use App\Models\User;
use App\Notifications\SchoolNotification;
use App\Services\AuditService;
use App\Services\ConsentService;
use App\Services\CourseRecordService;
use App\Services\StudentSupportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StaffStudentMessageController extends Controller
{
    public function contacts(Request $request, CourseOffering $offering, CourseRecordService $courses, StudentSupportService $support, ConsentService $consent, AuditService $audit): View
    {
        $actor = $request->user();
        $isTeacher = $actor->activeRole() === RoleName::Teacher;
        $this->authorizeCourse($request, $offering, $support);
        $roster = $courses->roster($offering, now()->toDateString())
            ->where('status', 'active')->whereHas('student', fn ($query) => $query->where('is_active', true))
            ->with('student.guardians.roles')->paginate(25);
        $contacts = [];
        foreach ($roster as $enrollment) {
            $contacts[$enrollment->id] = $enrollment->student->guardians->filter(fn ($guardian) => $guardian->pivot->is_active && $guardian->pivot->verified_at && $guardian->hasRole(RoleName::Guardian)
                && $consent->hasValidConsent($guardian));
            $audit->record('course.family_contacts_view', $enrollment->student, ['offering_id' => $offering->id]);
        }

        $tutor = $this->assignedTutor($offering);
        $canMessageStaff = $tutor && $offering->teacher?->hasRole(RoleName::Teacher)
            && $tutor->id !== $offering->teacher_id && ($isTeacher || $actor->id === $tutor->id);

        return view('courses.contacts', compact('offering', 'roster', 'contacts', 'isTeacher', 'canMessageStaff'));
    }

    public function thread(Request $request, CourseOffering $offering, Enrollment $enrollment, StudentSupportService $support, AuditService $audit): View|RedirectResponse
    {
        $actor = $request->user();
        if ($request->isMethod('post')) {
            $data = $request->validate(['body' => ['required', 'string', 'max:3000']]);
            DB::transaction(function () use ($request, $offering, $enrollment, $support, $audit, $actor, $data): void {
                $offering = CourseOffering::whereKey($offering->id)->lockForUpdate()->firstOrFail();
                $enrollment = Enrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
                [$tutor, $teacher] = $this->participants($request, $offering, $enrollment, $support);
                $message = StaffStudentMessage::create([
                    'course_offering_id' => $offering->id,
                    'enrollment_id' => $enrollment->id,
                    'tutor_id' => $tutor->id,
                    'teacher_id' => $teacher->id,
                    'sender_id' => $actor->id,
                    'body' => $data['body'],
                ]);
                $recipient = $actor->id === $teacher->id ? $tutor : $teacher;
                $recipient->notify(new SchoolNotification('Mensaje de coordinación escolar', 'Consulta tu conversación en el portal.', route('courses.staff-messages', [$offering, $enrollment]), ['school_year_id' => $enrollment->school_year_id]));
                $audit->record('course.staff_message_sent', $message);
            });

            return back()->with('status', 'Mensaje enviado.');
        }

        [$tutor, $teacher] = $this->participants($request, $offering, $enrollment, $support);
        $query = StaffStudentMessage::where('course_offering_id', $offering->id)->where('enrollment_id', $enrollment->id)
            ->where('tutor_id', $tutor->id)->where('teacher_id', $teacher->id);
        (clone $query)->where('sender_id', '!=', $actor->id)->whereNull('read_at')->update(['read_at' => now()]);
        $messages = $query->with('sender')->latest('id')->paginate(25);
        $audit->record('course.staff_thread_view', $enrollment, ['offering_id' => $offering->id]);

        return view('courses.staff-messages', compact('offering', 'enrollment', 'messages', 'tutor', 'teacher'));
    }

    private function authorizeCourse(Request $request, CourseOffering $offering, StudentSupportService $support): void
    {
        $actor = $request->user();
        if ($actor->activeRole() === RoleName::Teacher) {
            abort_unless($actor->permits('courses.record') && $support->teacherOfferings($actor)->whereKey($offering->id)->exists(), 403);

            return;
        }
        abort_unless($actor->activeRole() === RoleName::Tutor && $actor->permits('communications.send') && $actor->can('view', $offering)
            && $offering->is_active && $offering->course->is_active && $offering->section->is_active && $offering->section->schoolYear->is_active, 403);
    }

    /** @return array{0: User, 1: User} */
    private function participants(Request $request, CourseOffering $offering, Enrollment $enrollment, StudentSupportService $support): array
    {
        $this->authorizeCourse($request, $offering, $support);
        abort_unless($enrollment->section_id === $offering->section_id && $enrollment->status === 'active'
            && $enrollment->starts_on->toDateString() <= now()->toDateString() && ! $enrollment->ends_on
            && $enrollment->student?->is_active, 403);
        $teacher = $offering->teacher;
        $tutor = $this->assignedTutor($offering);
        abort_unless($tutor && $teacher?->hasRole(RoleName::Teacher) && $tutor->id !== $teacher->id
            && in_array($request->user()->id, [$tutor->id, $teacher->id], true), 403);

        return [$tutor, $teacher];
    }

    private function assignedTutor(CourseOffering $offering): ?User
    {
        return $offering->section->tutors()->wherePivot('is_active', true)
            ->where('users.is_active', true)->whereHas('roles', fn ($query) => $query->where('name', RoleName::Tutor->value)
            ->whereHas('permissions', fn ($permissions) => $permissions->where('name', 'communications.send'))
            ->whereHas('permissions', fn ($permissions) => $permissions->where('name', 'students.view')))
            ->orderBy('users.id')->first();
    }
}
