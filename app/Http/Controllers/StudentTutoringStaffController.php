<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\TutoringSession;
use App\Services\AuditService;
use App\Services\StudentTutoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StudentTutoringStaffController extends Controller
{
    public function index(Request $request, StudentTutoringService $tutoring): View
    {
        $user = $request->user();
        $role = $user->activeRole();
        $entries = $tutoring->staffEntries($user)->join('students as st', 'st.id', '=', 'origin.student_id')
            ->whereNull('st.deleted_at')->where('st.is_active', true)
            ->addSelect('st.first_name', 'st.last_name')
            ->orderByRaw("CASE WHEN entry.attended_at IS NOT NULL THEN 2 WHEN entry.category = 'risk' THEN 0 ELSE 1 END")
            ->latest('entry.id')->paginate(12, pageName: 'entries');
        $canPublish = in_array($role, [RoleName::Tutor, RoleName::Teacher], true);
        $activities = $canPublish ? $tutoring->staffActivities($user)->latest('a.opens_at')->paginate(12, pageName: 'activities') : null;
        $sessions = $canPublish
            ? TutoringSession::where('status', '!=', 'cancelled')->where('scheduled_on', '>=', today())
                ->whereIn('section_id', $tutoring->staffSections($user)->select('sections.id'))
                ->with('section.grade')->orderBy('scheduled_on')->limit(100)->get()
            : collect();
        $instruments = $role === RoleName::Teacher
            ? ['forum' => config('student_tutoring.instruments.forum')]
            : config('student_tutoring.instruments');

        return view('student-tutoring.staff', compact('activities', 'sessions', 'entries', 'role', 'instruments', 'canPublish'));
    }

    public function publish(Request $request, StudentTutoringService $tutoring, AuditService $audit): RedirectResponse
    {
        $role = $request->user()->activeRole();
        abort_unless(in_array($role, [RoleName::Tutor, RoleName::Teacher], true), 403);
        $data = $request->validate([
            'session_id' => ['required', 'integer'],
            'instrument_phase' => ['required', 'string', 'max:50'],
            'prompt' => ['nullable', 'string', 'max:3000'],
            'opens' => ['required', 'date_format:H:i'],
            'closes' => ['required', 'date_format:H:i', 'after:opens'],
        ]);
        [$instrument, $phase] = array_pad(explode(':', $data['instrument_phase'], 2), 2, '');
        $instruments = config('student_tutoring.instruments');
        if (! in_array($phase, $instruments[$instrument]['phases'] ?? [], true)) {
            throw ValidationException::withMessages(['instrument_phase' => 'Selecciona una actividad de la lista.']);
        }
        abort_if($role === RoleName::Teacher && $instrument !== 'forum', 403);
        if ($instrument === 'forum' && empty($data['prompt'])) {
            throw ValidationException::withMessages(['prompt' => 'Escribe el caso y la pregunta que orientará la discusión.']);
        }

        $session = TutoringSession::whereIn('section_id', $tutoring->staffSections($request->user())->select('sections.id'))
            ->where('status', '!=', 'cancelled')->with('section.schoolYear')->findOrFail($data['session_id']);
        $day = $session->scheduled_on->toDateString();
        if ($day < today()->toDateString() || $day.' '.$data['closes'].':00' <= now()->toDateTimeString()) {
            throw ValidationException::withMessages(['closes' => 'Elige una sesión y una hora de cierre futuras.']);
        }

        DB::transaction(function () use ($request, $audit, $session, $day, $data, $instrument, $phase): void {
            SchoolYear::available()->whereKey($session->section->school_year_id)->sharedLock()->firstOrFail();
            $session = TutoringSession::where('status', '!=', 'cancelled')->lockForUpdate()->findOrFail($session->id);
            if ($session->scheduled_on->toDateString() !== $day) {
                throw ValidationException::withMessages(['session_id' => 'La fecha de la sesión cambió. Recarga la página y vuelve a publicarla.']);
            }
            $activity = DB::table('student_tutoring_activities')->where('tutoring_session_id', $session->id)
                ->where('instrument', $instrument)->where('phase', $phase)->first();
            if ($activity) {
                throw ValidationException::withMessages(['instrument_phase' => 'Esta actividad ya está publicada en la sesión.']);
            }
            DB::table('student_tutoring_activities')->insert([
                'tutoring_session_id' => $session->id, 'author_id' => $request->user()->id,
                'instrument' => $instrument, 'phase' => $phase, 'version' => config('student_tutoring.version'),
                'prompt' => $instrument === 'forum' ? $data['prompt'] : null,
                'opens_at' => $day.' '.$data['opens'].':00', 'closes_at' => $day.' '.$data['closes'].':00',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $audit->record('student.activity_published', $session, compact('instrument', 'phase'));
        }, 3);

        return to_route('student-tutoring.staff.index')->with('status', 'Actividad publicada para la sección.');
    }

    public function activity(Request $request, int $activity, StudentTutoringService $tutoring, AuditService $audit): View
    {
        $actor = $request->user();
        $activity = $tutoring->staffActivities($actor)->where('a.id', $activity)->first() ?? abort(404);
        $instrument = $tutoring->instrument($activity);
        if ($activity->instrument === 'forum' && $request->boolean('project')) {
            return view('student-tutoring.project', compact('activity'));
        }

        $responses = DB::table('student_tutoring_responses')->where('activity_id', $activity->id);
        $total = $responses->count();
        $details = collect();
        if (in_array($activity->instrument, ['forum', 'checkin'], true)) {
            $details = $responses->join('enrollments as e', 'e.id', '=', 'student_tutoring_responses.enrollment_id')
                ->join('students as st', 'st.id', '=', 'e.student_id')->whereNull('st.deleted_at')->where('st.is_active', true)
                ->when($activity->instrument === 'checkin', fn ($query) => $query
                    ->whereIn('e.id', $tutoring->activeEnrollments()->where('section_id', $activity->section_id)->select('enrollments.id')))
                ->select('student_tutoring_responses.*', 'st.first_name', 'st.last_name')->latest('student_tutoring_responses.id')->paginate(12);
            $audit->record('student.activity_responses_viewed', TutoringSession::findOrFail($activity->tutoring_session_id), ['instrument' => $activity->instrument]);
        }

        return view('student-tutoring.staff-activity', compact('activity', 'instrument', 'total', 'details', 'tutoring'));
    }

    public function moderate(Request $request, int $activity, int $response, StudentTutoringService $tutoring, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['moderation' => ['required', 'in:approved,hidden']]);
        $activity = $tutoring->staffActivities($request->user())->where('a.id', $activity)->where('a.instrument', 'forum')->first() ?? abort(404);
        DB::transaction(function () use ($activity, $response, $data, $audit): void {
            $session = TutoringSession::with('section')->findOrFail($activity->tutoring_session_id);
            SchoolYear::available()->whereKey($session->section->school_year_id)->sharedLock()->firstOrFail();
            $post = DB::table('student_tutoring_responses')->where('activity_id', $activity->id)->where('id', $response);
            abort_unless($post->exists(), 404);
            $post->update(['moderation' => $data['moderation'], 'updated_at' => now()]);
            $audit->record('student.forum_moderated', $session, ['response_id' => $response, 'moderation' => $data['moderation']]);
        }, 3);

        return back()->with('status', 'Visibilidad actualizada.');
    }

    public function entry(Request $request, int $entry, StudentTutoringService $tutoring, AuditService $audit): View
    {
        $entry = $tutoring->staffEntries($request->user())->where('entry.id', $entry)->first() ?? abort(404);
        $student = Student::where('is_active', true)->findOrFail($entry->student_id);
        $audit->record('student.confidential_entry_viewed', $student, ['entry_id' => $entry->id, 'kind' => $entry->kind]);
        $canRefer = $entry->kind === 'request' && $entry->referred_at === null && $request->user()->activeRole() === RoleName::Tutor;

        return view('student-tutoring.staff-entry', compact('entry', 'student', 'canRefer'));
    }

    public function feedback(Request $request, int $entry, StudentTutoringService $tutoring, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['reply', 'refer'])],
            'private_feedback' => ['required_if:action,reply', 'nullable', 'string', 'max:2400'],
        ]);
        $user = $request->user();
        $entry = $tutoring->staffEntries($user)->where('entry.id', $entry)->first() ?? abort(404);
        DB::transaction(function () use ($entry, $user, $tutoring, $data, $audit): void {
            SchoolYear::available()->whereKey($entry->school_year_id)->sharedLock()->firstOrFail();
            $query = $tutoring->staffEntries($user)->where('entry.id', $entry->id);
            $entry = $query->lockForUpdate()->first() ?? abort(404);
            $student = Student::where('is_active', true)->findOrFail($entry->student_id);
            if ($data['action'] === 'refer') {
                abort_unless($entry->kind === 'request' && $user->activeRole() === RoleName::Tutor, 403);
                if ($entry->referred_at !== null) {
                    return;
                }
                $changes = ['referred_at' => now()];
            } else {
                $changes = ['feedback' => Crypt::encryptString($data['private_feedback']), 'feedback_by' => $user->id, 'attended_at' => $entry->attended_at ?? now()];
            }
            DB::table('student_tutoring_entries')->where('id', $entry->id)->update($changes + ['updated_at' => now()]);
            $audit->record('student.confidential_'.$data['action'], $student, ['entry_id' => $entry->id, 'kind' => $entry->kind]);
        }, 3);

        return back()->with('status', $data['action'] === 'refer' ? 'Solicitud derivada a Coordinación de tutoría.' : 'Respuesta confidencial guardada.');
    }
}
