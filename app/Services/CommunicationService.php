<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\Communication;
use App\Models\CommunicationRecipient;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\SchoolNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CommunicationService
{
    public function reschedule(Communication $communication, array $data, User $actor): void
    {
        DB::transaction(function () use ($communication, $data, $actor): void {
            $communication = Communication::whereKey($communication->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->permits('communications.send') && $communication->user_id === $actor->id, 403);
            $recipients = $communication->recipients()->lockForUpdate()->get();
            if ($actor->activeRole() === RoleName::Tutor) {
                abort_if($recipients->isEmpty() || $recipients->whereNotIn('student_id', app(AccessScope::class)->students($actor)->pluck('students.id'))->isNotEmpty(), 403);
            }
            if ($communication->type !== 'appointment' || (int) $communication->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Comprueba que sea una citación y recarga su revisión actual.']);
            }
            DB::table('communication_schedule_changes')->insert(['communication_id' => $communication->id, 'user_id' => $actor->id, 'previous_at' => $communication->scheduled_at, 'scheduled_at' => Carbon::parse($data['scheduled_at'], 'America/Lima'), 'location' => $data['location'], 'reason' => $data['reason'], 'previous_responses' => json_encode($recipients->map->only(['id', 'user_id', 'response', 'response_note', 'responded_at', 'read_at'])->all(), JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $communication->forceFill(['scheduled_at' => Carbon::parse($data['scheduled_at'], 'America/Lima'), 'location' => $data['location'], 'revision' => $communication->revision + 1])->save();
            foreach ($recipients as $recipient) {
                $recipient->update(['read_at' => null, 'response' => 'pending', 'response_note' => null, 'responded_at' => null]);
                $guardian = User::find($recipient->user_id);
                $guardian?->notify(new SchoolNotification('Citación reprogramada', 'Consulta la nueva fecha y confirma tu respuesta en el portal.', route('communications.show', $recipient), ['kind' => 'communication', 'student_id' => $recipient->student_id, 'recipient_id' => $recipient->id]));
            }
            app(AuditService::class)->record('communication.rescheduled', $communication, ['revision' => $communication->revision]);
        });
    }

    public function send(array $data, User $actor): Communication
    {
        abort_unless($actor->permits('communications.send'), 403);
        if ($actor->activeRole() === RoleName::Tutor && in_array($data['target'], ['grade', 'school'], true)) {
            abort(403);
        }
        if ($data['type'] === 'appointment' && $data['target'] !== 'student') {
            throw ValidationException::withMessages(['target' => 'Las citaciones son individuales. Selecciona un estudiante.']);
        }

        return DB::transaction(function () use ($data, $actor) {
            $query = Enrollment::where('school_year_id', $data['school_year_id'])->where('status', 'active')->whereHas('student', fn ($q) => $q->where('is_active', true))->whereIn('section_id', app(AccessScope::class)->sections($actor)->select('sections.id'));
            if ($data['target'] === 'student') {
                $query->whereHas('student', fn ($q) => $q->where('dni', $data['student_dni']));
            }
            if ($data['target'] === 'section') {
                $query->where('section_id', $data['section_id']);
            }
            if ($data['target'] === 'grade') {
                $query->whereHas('section', fn ($q) => $q->where('grade_id', $data['grade_id']));
            }
            $studentIds = $query->pluck('student_id')->unique();
            if ($studentIds->isEmpty()) {
                throw ValidationException::withMessages(['target' => 'No hay estudiantes autorizados para ese destinatario y año.']);
            }
            $links = DB::table('guardian_student')->join('users', 'users.id', '=', 'guardian_student.user_id')->whereIn('student_id', $studentIds)->where('guardian_student.is_active', true)->whereNotNull('guardian_student.verified_at')->where('users.is_active', true)->whereNull('users.deleted_at')->select('guardian_student.user_id', 'student_id')->get();
            if ($links->isEmpty()) {
                throw ValidationException::withMessages(['target' => 'Primero vincula al menos un apoderado autorizado.']);
            }
            $communication = Communication::create(['user_id' => $actor->id, 'school_year_id' => $data['school_year_id'], 'type' => $data['type'], 'title' => $data['title'], 'body' => $data['body'], 'scheduled_at' => $data['type'] === 'appointment' ? Carbon::parse($data['scheduled_at'], 'America/Lima') : null, 'location' => $data['type'] === 'appointment' ? $data['location'] : null]);
            foreach ($links as $link) {
                $guardian = User::find($link->user_id);
                if (! $guardian?->hasRole(RoleName::Guardian)) {
                    continue;
                }
                $recipient = CommunicationRecipient::create(['communication_id' => $communication->id, 'user_id' => $link->user_id, 'student_id' => $link->student_id]);
                $guardian->notify(new SchoolNotification($communication->title, 'Tienes una nueva '.($communication->type === 'appointment' ? 'citación' : 'comunicación').' del colegio. Ingresa al portal para revisarla.', route('communications.show', $recipient), ['kind' => 'communication', 'student_id' => $link->student_id, 'recipient_id' => $recipient->id]));
            }
            if (! $communication->recipients()->exists()) {
                throw ValidationException::withMessages(['target' => 'No hay apoderados activos con el rol correspondiente.']);
            }
            app(AuditService::class)->record('communication.sent', $communication, ['recipient_count' => $communication->recipients()->count()]);

            return $communication;
        });
    }

    public function respond(CommunicationRecipient $recipient, array $data, User $actor): void
    {
        DB::transaction(function () use ($recipient, $data, $actor) {
            $recipient = CommunicationRecipient::with('communication')->whereKey($recipient->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('respond', $recipient);
            if ($recipient->communication->type !== 'appointment') {
                throw ValidationException::withMessages(['response' => 'Este comunicado solo requiere confirmación de lectura.']);
            }
            $before = $recipient->only(['response', 'response_note']);
            $recipient->update(['read_at' => $recipient->read_at ?? now(), 'response' => $data['response'], 'response_note' => $data['response_note'] ?? null, 'responded_at' => now()]);
            app(AuditService::class)->record('communication.responded', $recipient, ['before' => $before, 'after' => $recipient->only(['response', 'response_note'])]);
        });
    }
}
