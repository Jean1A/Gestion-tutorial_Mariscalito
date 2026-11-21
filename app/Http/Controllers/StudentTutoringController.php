<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Services\StudentTutoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StudentTutoringController extends Controller
{
    public function home(Request $request, StudentTutoringService $tutoring): View
    {
        $enrollment = $tutoring->currentEnrollment($request->user());
        $activities = collect();
        $completed = collect();
        if ($enrollment) {
            $responses = DB::table('student_tutoring_responses')->where('enrollment_id', $enrollment->id)->select('activity_id');
            $activities = $tutoring->activities()->where('ts.section_id', $enrollment->section_id)
                ->whereDate('a.opens_at', '>=', $enrollment->starts_on)
                ->when(! $tutoring->researchAuthorized($enrollment), fn ($query) => $query->where('a.phase', 'practice'))
                ->where(fn ($query) => $query->whereDate('a.opens_at', today())->orWhereIn('a.id', $responses))
                ->orderByRaw('CASE WHEN a.opens_at <= ? AND a.closes_at > ? THEN 0 ELSE 1 END', [now(), now()])
                ->orderByDesc('a.opens_at')->orderBy('a.id')->paginate(12);
            $completed = $responses->pluck('activity_id');
            $tutoring->trackAccess($activities->getCollection(), $enrollment);
        }

        return view('student-tutoring.home', compact('enrollment', 'activities', 'completed', 'tutoring'));
    }

    public function activity(Request $request, int $activity, StudentTutoringService $tutoring): View
    {
        $enrollment = $tutoring->currentEnrollment($request->user()) ?? abort(404);
        $activity = $this->studentActivity($tutoring, $enrollment, $activity);
        $instrument = $tutoring->instrument($activity);
        $response = DB::table('student_tutoring_responses')->where('activity_id', $activity->id)->where('enrollment_id', $enrollment->id)->first();
        $open = $tutoring->isOpen($activity);
        abort_unless($open || $response || ($activity->instrument === 'forum' && $activity->opens_at <= now()->toDateTimeString()), 404);
        $tutoring->trackAccess(collect([$activity]), $enrollment);
        $answers = $response && $activity->phase === 'practice' ? ($tutoring->readPayload($response)['answers'] ?? []) : [];
        $scores = $answers ? $tutoring->scaleScores($activity->instrument, $answers) : [];
        $posts = $activity->instrument === 'forum'
            ? DB::table('student_tutoring_responses')->where('activity_id', $activity->id)->where('moderation', 'approved')->orderBy('id')->paginate(15)
            : collect();

        return view('student-tutoring.activity', compact('activity', 'instrument', 'response', 'open', 'scores', 'answers', 'posts', 'tutoring'));
    }

    public function submit(Request $request, int $activity, StudentTutoringService $tutoring): RedirectResponse
    {
        DB::transaction(function () use ($request, $activity, $tutoring): void {
            $enrollment = $this->lockedEnrollment($request, $tutoring);
            $activity = $this->studentActivity($tutoring, $enrollment, $activity);
            abort_unless($tutoring->isOpen($activity), 403, 'La actividad está fuera del horario programado.');
            $instrument = $tutoring->instrument($activity);
            $research = $activity->phase !== 'practice';
            if ($activity->instrument === 'forum') {
                $data = $request->validate(['forum_text' => ['required', 'string', 'max:1500']]);
                $payload = ['text' => $data['forum_text']];
            } else {
                $data = $request->validate([
                    'student_answers' => ['required', 'list', 'size:'.count($instrument['items'])],
                    'student_answers.*' => ['required', 'integer', 'between:1,5'],
                    'assent' => [$research ? 'accepted' : 'nullable'],
                ]);
                $payload = ['answers' => array_map('intval', $data['student_answers'])];
            }
            DB::table('student_tutoring_responses')->insertOrIgnore([
                'activity_id' => $activity->id,
                'enrollment_id' => $enrollment->id,
                'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
                'moderation' => $activity->instrument === 'forum' ? 'pending' : null,
                'assented_at' => $research ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $tutoring->trackAccess(collect([$activity]), $enrollment);
        }, 3);

        return to_route('student-tutoring.activity', $activity)->with('status', 'Tu participación está registrada.');
    }

    public function entries(Request $request, string $kind, StudentTutoringService $tutoring): View
    {
        $enrollment = $tutoring->currentEnrollment($request->user()) ?? abort(404);
        $entries = $tutoring->ownEntries($enrollment)->where('entry.kind', $kind)->latest('entry.id')->paginate(10);
        $periods = $kind === 'goal'
            ? AcademicPeriod::where('school_year_id', $enrollment->school_year_id)->orderBy('starts_on')->get()
            : collect();

        return view('student-tutoring.entries', compact('entries', 'kind', 'periods'));
    }

    public function saveEntry(Request $request, string $kind, StudentTutoringService $tutoring): RedirectResponse
    {
        $rules = ['private_content' => ['required', 'string', 'max:2400']];
        if ($kind === 'request') {
            $rules['category'] = ['required', Rule::in(array_keys(config('student_tutoring.request_categories')))];
        }
        if ($kind === 'goal') {
            $rules['academic_period_id'] = ['required', 'integer'];
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($request, $kind, $tutoring, $data): void {
            $enrollment = $this->lockedEnrollment($request, $tutoring);
            $period = $kind === 'goal'
                ? AcademicPeriod::where('school_year_id', $enrollment->school_year_id)->where('is_active', true)->findOrFail($data['academic_period_id'])
                : null;
            DB::table('student_tutoring_entries')->insert([
                'enrollment_id' => $enrollment->id,
                'kind' => $kind,
                'category' => $data['category'] ?? null,
                'academic_period_id' => $period?->id,
                'content' => Crypt::encryptString($data['private_content']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);

        return to_route('student-tutoring.entries', $kind)->with('status', 'Registro guardado.');
    }

    public function goal(Request $request, int $entry, StudentTutoringService $tutoring): View
    {
        $enrollment = $tutoring->currentEnrollment($request->user()) ?? abort(404);
        $goal = $tutoring->ownEntries($enrollment)->where('entry.kind', 'goal')->where('entry.id', $entry)->first() ?? abort(404);
        $updates = DB::table('student_goal_updates')->where('entry_id', $entry)->latest('id')->paginate(12);

        return view('student-tutoring.goal', compact('goal', 'updates'));
    }

    public function progress(Request $request, int $entry, StudentTutoringService $tutoring): RedirectResponse
    {
        $data = $request->validate(['progress' => ['required', 'integer', 'between:0,100'], 'private_note' => ['required', 'string', 'max:1200']]);
        DB::transaction(function () use ($request, $entry, $tutoring, $data): void {
            $enrollment = $this->lockedEnrollment($request, $tutoring);
            abort_unless($tutoring->ownEntries($enrollment)->where('entry.kind', 'goal')->where('entry.id', $entry)->exists(), 404);
            DB::table('student_goal_updates')->insert([
                'entry_id' => $entry, 'progress' => $data['progress'],
                'note' => Crypt::encryptString($data['private_note']), 'created_at' => now(),
            ]);
        }, 3);

        return to_route('student-tutoring.goal', $entry)->with('status', 'Avance registrado.');
    }

    private function studentActivity(StudentTutoringService $tutoring, Enrollment $enrollment, int $id): object
    {
        return $tutoring->activities()->where('a.id', $id)->where('ts.section_id', $enrollment->section_id)
            ->whereDate('a.opens_at', '>=', $enrollment->starts_on)
            ->when(! $tutoring->researchAuthorized($enrollment), fn ($query) => $query->where('a.phase', 'practice'))
            ->first() ?? abort(404);
    }

    private function lockedEnrollment(Request $request, StudentTutoringService $tutoring): Enrollment
    {
        $enrollment = $tutoring->currentEnrollment($request->user()) ?? abort(404);
        SchoolYear::available()->whereKey($enrollment->school_year_id)->sharedLock()->firstOrFail();

        return $tutoring->activeEnrollments()->whereKey($enrollment->id)
            ->whereHas('student', fn ($students) => $students->where('is_active', true)->where('dni', $request->user()->dni))
            ->lockForUpdate()->firstOrFail();
    }
}
