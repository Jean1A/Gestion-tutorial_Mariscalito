<?php

namespace App\Http\Controllers;

use App\Http\Requests\ParentForumRequest;
use App\Http\Requests\ParentSurveyRequest;
use App\Models\FamilyWorkshop;
use App\Models\MeetingAttendance;
use App\Models\WorkshopLesson;
use App\Models\WorkshopSurvey;
use App\Services\AuditService;
use App\Services\FamilySchoolService;
use App\Services\GuardianPortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FamilySchoolController extends Controller
{
    public function index(Request $request, FamilySchoolService $family, GuardianPortalService $portal, AuditService $audit): View
    {
        $child = $portal->selected($request->user());
        $enrollment = $child ? $portal->enrollment($child) : null;
        $workshops = $family->published()->with('lessons')->latest()->paginate(10);
        $progress = [];
        foreach ($workshops as $workshop) {
            $progress[$workshop->id] = $family->progress($workshop, $request->user());
        }
        $meetings = $family->meetings($request->user())->with(['attendances' => fn ($q) => $q->where('user_id', $request->user()->id)])->orderByDesc('scheduled_at')->limit(20)->get();
        $attendance = MeetingAttendance::where('user_id', $request->user()->id)->whereIn('family_meeting_id', $family->meetings($request->user())->select('id'))->get();
        $audit->record('family.school_view', $request->user());

        return view('guardian.family', compact('child', 'enrollment', 'workshops', 'progress', 'meetings', 'attendance'));
    }

    public function workshop(Request $request, FamilyWorkshop $workshop, FamilySchoolService $family, GuardianPortalService $portal, AuditService $audit): View
    {
        $family->accessible($workshop);
        $child = $portal->selected($request->user());
        $enrollment = $child ? $portal->enrollment($child) : null;
        $workshop->load(['lessons.completions' => fn ($q) => $q->where('user_id', $request->user()->id)]);
        $progress = $family->progress($workshop, $request->user());
        $survey = WorkshopSurvey::where('family_workshop_id', $workshop->id)->where('user_id', $request->user()->id)->first();
        $posts = $workshop->posts()->where(fn ($q) => $q->where('status', 'published')->orWhere('user_id', $request->user()->id))->latest()->paginate(20);
        $audit->record('workshop.view', $workshop);

        return view('guardian.workshop', compact('child', 'enrollment', 'workshop', 'progress', 'survey', 'posts'));
    }

    public function complete(Request $request, WorkshopLesson $lesson, FamilySchoolService $family): RedirectResponse
    {
        $family->complete($lesson, $request->user());

        return back()->with('status', 'Contenido marcado como completado.');
    }

    public function survey(ParentSurveyRequest $request, FamilyWorkshop $workshop, FamilySchoolService $family): RedirectResponse
    {
        $family->survey($workshop, $request->validated(), $request->user());

        return back()->with('status', 'Gracias. Tu encuesta quedó registrada.');
    }

    public function post(ParentForumRequest $request, FamilyWorkshop $workshop, FamilySchoolService $family): RedirectResponse
    {
        $family->post($workshop, $request->validated(), $request->user());

        return back()->with('status', 'Tu participación está pendiente de revisión por el colegio.');
    }
}
