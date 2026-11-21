<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\TutoringSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class StudentTutoringService
{
    public function currentEnrollment(User $user): ?Enrollment
    {
        return $this->activeEnrollments()
            ->where('student_id', $user->student_profile_id)
            ->whereHas('student', fn (Builder $students) => $students->where('is_active', true)->where('dni', $user->dni))
            ->orderByDesc('starts_on')->orderByDesc('id')->first();
    }

    public function activeEnrollments(): Builder
    {
        $today = today()->toDateString();

        return Enrollment::where('status', 'active')->where('starts_on', '<=', $today)
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->whereIn('school_year_id', SchoolYear::available()->where('is_active', true)->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->select('id'))
            ->whereHas('section', fn (Builder $sections) => $sections->where('is_active', true));
    }

    public function staffSections(User $user): Builder
    {
        return match ($user->activeRole()) {
            RoleName::Tutor => app(AccessScope::class)->sections($user),
            RoleName::Teacher => Section::whereIn('id', CourseOffering::where('teacher_id', $user->id)->where('is_active', true)->select('section_id')),
            default => Section::whereRaw('1 = 0'),
        };
    }

    public function activities(): Query
    {
        return DB::table('student_tutoring_activities as a')
            ->join('tutoring_sessions as ts', 'ts.id', '=', 'a.tutoring_session_id')
            ->whereIn('ts.id', TutoringSession::where('status', '!=', 'cancelled')->select('id'))
            ->select('a.*', 'ts.section_id', 'ts.topic');
    }

    public function staffActivities(User $user): Query
    {
        return $this->activities()->whereIn('ts.section_id', $this->staffSections($user)->select('sections.id'))
            ->when($user->activeRole() === RoleName::Teacher, fn (Query $query) => $query->where('a.instrument', 'forum')->where('a.author_id', $user->id));
    }

    public function researchAuthorized(Enrollment $enrollment): bool
    {
        return DB::table('student_research_permissions')->where('school_year_id', $enrollment->school_year_id)
            ->where('student_id', $enrollment->student_id)->where('authorized', true)->exists();
    }

    public function isOpen(object $activity): bool
    {
        $now = now()->toDateTimeString();

        return $activity->opens_at <= $now && $activity->closes_at > $now;
    }

    public function trackAccess(Collection $activities, Enrollment $enrollment): void
    {
        $records = $activities->filter($this->isOpen(...))->unique('tutoring_session_id')->map(fn (object $activity): array => [
            'tutoring_session_id' => $activity->tutoring_session_id,
            'enrollment_id' => $enrollment->id,
            'accessed_at' => now(),
        ])->values()->all();
        if ($records !== []) {
            DB::table('student_tutoring_accesses')->insertOrIgnore($records);
        }
    }

    public function entries(): Query
    {
        return DB::table('student_tutoring_entries as entry')
            ->join('enrollments as origin', 'origin.id', '=', 'entry.enrollment_id')
            ->whereIn('origin.school_year_id', SchoolYear::available()->select('id'))
            ->select('entry.*', 'origin.student_id', 'origin.school_year_id');
    }

    public function ownEntries(Enrollment $enrollment): Query
    {
        return $this->entries()->where('origin.student_id', $enrollment->student_id)
            ->where('origin.school_year_id', $enrollment->school_year_id);
    }

    public function staffEntries(User $user): Query
    {
        $query = $this->entries()->whereIn('entry.kind', ['journal', 'request']);
        if ($user->activeRole() === RoleName::Coordinator && $user->can('coordinate-tutors')) {
            return $query->where('entry.kind', 'request')->whereNotNull('entry.referred_at');
        }
        if ($user->activeRole() !== RoleName::Tutor) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists($this->activeEnrollments()
            ->whereColumn('enrollments.student_id', 'origin.student_id')
            ->whereColumn('enrollments.school_year_id', 'origin.school_year_id')
            ->whereIn('section_id', $this->staffSections($user)->select('sections.id'))
            ->selectRaw('1')->toBase());
    }

    public function pendingRequests(User $user): int
    {
        return $this->staffEntries($user)->where('entry.kind', 'request')->whereNull('entry.attended_at')
            ->whereIn('origin.student_id', Student::where('is_active', true)->select('id'))->count();
    }

    public function instrument(object $activity): array
    {
        abort_unless($activity->version === config('student_tutoring.version'), 404);

        return config('student_tutoring.instruments.'.$activity->instrument) ?? abort(404);
    }

    /** @param list<int> $answers */
    public function scaleScores(string $instrument, array $answers): array
    {
        if ($instrument === 'sus') {
            $total = 0;
            foreach (array_slice($answers, 0, 10) as $index => $value) {
                $total += $index % 2 === 0 ? $value - 1 : 5 - $value;
            }

            return ['Usabilidad SUS (0–100)' => $total * 2.5, 'Satisfacción (1–5)' => $answers[10]];
        }

        $scores = [];
        foreach (config('student_tutoring.instruments.'.$instrument.'.scales', []) as $label => $indexes) {
            $scores[$label] = array_sum(array_map(fn (int $index): int => $answers[$index], $indexes)) / count($indexes);
        }

        return $scores;
    }

    /** @return array{answers?: list<int>, text?: string} */
    public function readPayload(object $response): array
    {
        return json_decode(Crypt::decryptString($response->payload), true, flags: JSON_THROW_ON_ERROR);
    }
}
