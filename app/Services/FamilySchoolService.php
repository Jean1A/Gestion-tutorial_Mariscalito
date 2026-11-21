<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\FamilyMeeting;
use App\Models\FamilyWorkshop;
use App\Models\ForumPost;
use App\Models\MeetingAttendance;
use App\Models\User;
use App\Models\WorkshopCompletion;
use App\Models\WorkshopLesson;
use App\Models\WorkshopSurvey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class FamilySchoolService
{
    public function published(): Builder
    {
        return FamilyWorkshop::where('is_active', true)->where('is_published', true);
    }

    public function accessible(FamilyWorkshop $workshop): void
    {
        abort_unless($workshop->is_active && $workshop->is_published, 404);
    }

    public function progress(FamilyWorkshop $workshop, User $user): array
    {
        $total = $workshop->lessons()->count();
        $done = WorkshopCompletion::where('user_id', $user->id)->whereIn('workshop_lesson_id', $workshop->lessons()->select('id'))->count();

        return ['total' => $total, 'completed' => $done, 'percentage' => $total ? (int) floor($done * 100 / $total) : 0, 'finished' => $total > 0 && $done === $total];
    }

    public function complete(WorkshopLesson $lesson, User $user): void
    {
        Gate::forUser($user)->authorize('guardian-portal');
        DB::transaction(function () use ($lesson, $user) {
            $workshop = FamilyWorkshop::whereKey($lesson->family_workshop_id)->lockForUpdate()->firstOrFail();
            $this->accessible($workshop);
            $completion = WorkshopCompletion::firstOrCreate(['workshop_lesson_id' => $lesson->id, 'user_id' => $user->id]);
            if ($completion->wasRecentlyCreated) {
                app(AuditService::class)->record('workshop.lesson_completed', $completion, [], $user->id);
            }
        });
    }

    public function survey(FamilyWorkshop $workshop, array $data, User $user): void
    {
        Gate::forUser($user)->authorize('guardian-portal');
        DB::transaction(function () use ($workshop, $data, $user) {
            $workshop = FamilyWorkshop::whereKey($workshop->id)->lockForUpdate()->firstOrFail();
            $this->accessible($workshop);
            if (! $this->progress($workshop, $user)['finished']) {
                throw ValidationException::withMessages(['rating' => 'Completa todos los contenidos antes de responder la encuesta.']);
            }
            $survey = WorkshopSurvey::updateOrCreate(['family_workshop_id' => $workshop->id, 'user_id' => $user->id], $data);
            app(AuditService::class)->record('workshop.survey_saved', $survey, [], $user->id);
        });
    }

    public function post(FamilyWorkshop $workshop, array $data, User $user): void
    {
        Gate::forUser($user)->authorize('guardian-portal');
        $this->accessible($workshop);
        $post = ForumPost::create(['family_workshop_id' => $workshop->id, 'user_id' => $user->id, 'body' => $data['body'], 'status' => 'pending']);
        app(AuditService::class)->record('forum.submitted', $post, [], $user->id);
    }

    public function moderate(ForumPost $post, array $data, User $user): void
    {
        Gate::forUser($user)->authorize('manage-family');
        DB::transaction(function () use ($post, $data, $user) {
            $post = ForumPost::whereKey($post->id)->lockForUpdate()->firstOrFail();
            $before = $post->status;
            $post->update(['status' => $data['status'], 'moderated_by' => $user->id, 'moderated_at' => now()]);
            app(AuditService::class)->record('forum.moderated', $post, ['before' => $before, 'after' => $data['status']], $user->id);
        });
    }

    public function meetings(User $user): Builder
    {
        $sections = $user->students()->wherePivot('is_active', true)->wherePivotNotNull('verified_at')->with('enrollments')->get()->flatMap(fn ($student) => $student->enrollments->where('status', 'active')->pluck('section_id'))->unique();

        return FamilyMeeting::where('is_active', true)->where('is_published', true)->where(fn ($q) => $q->whereNull('section_id')->orWhereIn('section_id', $sections));
    }

    public function saveWorkshop(array $data, User $user, ?FamilyWorkshop $workshop = null): void
    {
        Gate::forUser($user)->authorize('manage-family');
        DB::transaction(function () use ($data, $user, $workshop) {
            $workshop = $workshop ? FamilyWorkshop::whereKey($workshop->id)->lockForUpdate()->firstOrFail() : new FamilyWorkshop;
            $before = $workshop->exists ? $workshop->only(['title', 'description', 'is_published', 'is_active']) : null;
            if ($workshop->exists) {
                $lessons = $workshop->lessons()->get();
                if ($lessons->count() !== count($data['lessons'])) {
                    throw ValidationException::withMessages(['lessons' => 'Una edición conserva los contenidos existentes y su historial. Crea otro taller para cambiar su estructura.']);
                }
                if (WorkshopCompletion::whereIn('workshop_lesson_id', $lessons->pluck('id'))->exists()) {
                    foreach ($lessons as $index => $lesson) {
                        if ($lesson->title !== $data['lessons'][$index]['title'] || $lesson->content !== $data['lessons'][$index]['content']) {
                            throw ValidationException::withMessages(['lessons' => 'No se reemplazan contenidos que ya tienen participación. Crea una nueva versión del taller.']);
                        }
                    }
                }
            }
            $workshop->fill(['title' => $data['title'], 'description' => $data['description'], 'is_published' => $data['is_published'], 'is_active' => $data['is_active'], 'user_id' => $workshop->user_id ?? $user->id])->save();
            foreach ($data['lessons'] as $index => $lesson) {
                $workshop->lessons()->updateOrCreate(['position' => $index + 1], $lesson);
            }
            app(AuditService::class)->record('workshop.saved', $workshop, ['before' => $before, 'after' => $workshop->only(['title', 'is_published', 'is_active'])], $user->id);
        });
    }

    public function saveMeeting(array $data, User $user, ?FamilyMeeting $meeting = null): void
    {
        Gate::forUser($user)->authorize('manage-family');
        DB::transaction(function () use ($data, $user, $meeting) {
            $meeting = $meeting ? FamilyMeeting::whereKey($meeting->id)->lockForUpdate()->firstOrFail() : new FamilyMeeting;
            if ($meeting->exists && $meeting->attendances()->exists() && (($meeting->section_id ?? null) != ($data['section_id'] ?? null) || $meeting->scheduled_at->format('Y-m-d\TH:i') !== $data['scheduled_at'])) {
                throw ValidationException::withMessages(['scheduled_at' => 'Una reunión con asistencias conserva su fecha y destinatarios. Puedes desactivarla sin borrar el historial.']);
            }
            $before = $meeting->exists ? $meeting->only(['title', 'scheduled_at', 'location', 'is_published', 'is_active']) : null;
            $meeting->fill($data + ['user_id' => $meeting->user_id ?? $user->id])->save();
            app(AuditService::class)->record('family.meeting_saved', $meeting, ['before' => $before, 'after' => $meeting->only(['title', 'is_published', 'is_active'])], $user->id);
        });
    }

    public function attendance(FamilyMeeting $meeting, array $data, User $actor): void
    {
        Gate::forUser($actor)->authorize('manage-family');
        if (! $meeting->is_active || ! $meeting->is_published || $meeting->scheduled_at->isFuture()) {
            throw ValidationException::withMessages(['user_id' => 'La reunión debe estar publicada, activa y haber iniciado.']);
        }
        $guardian = User::findOrFail($data['user_id']);
        if (! $guardian->hasRole(RoleName::Guardian) || ! $this->meetings($guardian)->whereKey($meeting->id)->exists()) {
            throw ValidationException::withMessages(['user_id' => 'El apoderado no pertenece a los destinatarios de esta reunión.']);
        }
        $attendance = MeetingAttendance::updateOrCreate(['family_meeting_id' => $meeting->id, 'user_id' => $guardian->id], ['attended' => $data['attended'], 'recorded_by' => $actor->id]);
        app(AuditService::class)->record('family.attendance_saved', $attendance, ['attended' => $attendance->attended], $actor->id);
    }
}
