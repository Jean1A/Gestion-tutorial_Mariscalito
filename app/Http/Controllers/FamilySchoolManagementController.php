<?php

namespace App\Http\Controllers;

use App\Http\Requests\MeetingAttendanceRequest;
use App\Http\Requests\ModerateForumRequest;
use App\Http\Requests\SaveFamilyResourceRequest;
use App\Models\FamilyMeeting;
use App\Models\FamilyWorkshop;
use App\Models\ForumPost;
use App\Models\Section;
use App\Models\User;
use App\Services\AuditService;
use App\Services\FamilySchoolService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class FamilySchoolManagementController extends Controller
{
    public function index(AuditService $audit): View
    {
        Gate::authorize('manage-family');
        $workshops = FamilyWorkshop::with('lessons')->withCount('surveys')->withAvg('surveys', 'rating')->latest()->paginate(10, ['*'], 'workshops_page');
        $posts = ForumPost::with('workshop', 'author')->latest()->paginate(20, ['*'], 'posts_page');
        $meetings = FamilyMeeting::with('section.grade')->latest()->limit(30)->get();
        $sections = Section::with('grade', 'schoolYear')->where('is_active', true)->get();
        foreach ($posts as $post) {
            $audit->record('forum.moderation_view', $post);
        }

        return view('family.manage', compact('workshops', 'posts', 'meetings', 'sections'));
    }

    public function workshopForm(?FamilyWorkshop $workshop = null): View
    {
        Gate::authorize('manage-family');
        $subject = $workshop ?? new FamilyWorkshop;
        $lessons = $workshop?->lessons()->get() ?? collect();

        return view('family.workshop-form', compact('subject', 'lessons'));
    }

    public function saveWorkshop(SaveFamilyResourceRequest $request, FamilySchoolService $family, ?FamilyWorkshop $workshop = null): RedirectResponse
    {
        $family->saveWorkshop($request->validated(), $request->user(), $workshop);

        return redirect()->route('family.manage.index')->with('status', 'Taller guardado.');
    }

    public function moderate(ModerateForumRequest $request, ForumPost $post, FamilySchoolService $family): RedirectResponse
    {
        $family->moderate($post, $request->validated(), $request->user());

        return back()->with('status', 'Participación revisada.');
    }

    public function saveMeeting(SaveFamilyResourceRequest $request, FamilySchoolService $family, ?FamilyMeeting $meeting = null): RedirectResponse
    {
        $family->saveMeeting($request->validated(), $request->user(), $meeting);

        return back()->with('status', 'Reunión guardada.');
    }

    public function meeting(FamilyMeeting $meeting, AuditService $audit): View
    {
        Gate::authorize('manage-family');
        $guardians = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', 'guardian'))->whereHas('students', fn ($q) => $q->where('guardian_student.is_active', true)->whereNotNull('guardian_student.verified_at')->when($meeting->section_id, fn ($q) => $q->whereHas('enrollments', fn ($e) => $e->where('section_id', $meeting->section_id)->where('status', 'active'))))->orderBy('name')->paginate(25);
        $attendance = $meeting->attendances()->get()->keyBy('user_id');
        foreach ($guardians as $guardian) {
            $audit->record('family.meeting_roster_view', $guardian);
        }

        return view('family.meeting', compact('meeting', 'guardians', 'attendance'));
    }

    public function attendance(MeetingAttendanceRequest $request, FamilyMeeting $meeting, FamilySchoolService $family): RedirectResponse
    {
        $family->attendance($meeting, $request->validated(), $request->user());

        return back()->with('status', 'Asistencia del apoderado registrada.');
    }
}
