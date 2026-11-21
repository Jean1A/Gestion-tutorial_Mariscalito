<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\SaveSupportRecordRequest;
use App\Http\Requests\SupportFilterRequest;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\FamilyMessage;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\SupportProfile;
use App\Models\SupportRecord;
use App\Models\TutoringSession;
use App\Models\User;
use App\Notifications\SchoolNotification;
use App\Services\AccessScope;
use App\Services\AuditService;
use App\Services\StudentSupportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentSupportController extends Controller
{
    private function options(User $user, StudentSupportService $service): array
    {
        $role = $user->activeRole();
        $sections = $service->sections($user);
        $courses = $role === RoleName::Teacher ? $service->teacherOfferings($user) : CourseOffering::query();

        return [
            'layout' => $service->coordinator($user) ? 'layouts.coordination' : match ($role) {
                RoleName::Tutor => 'layouts.advisor',
                RoleName::Guardian => 'layouts.guardian',
                default => 'layouts.app',
            },
            'sections' => (clone $sections)->with('grade', 'schoolYear')->get(),
            'students' => $service->students($user)->with('enrollments')->orderBy('last_name')->get(),
            'supportCourses' => $courses->with('course', 'section.grade')->whereIn('section_id', $sections->select('sections.id'))->get(),
            'canWork' => $service->canWork($user),
            'canCreate' => $service->canCreate($user),
            'isReporter' => $service->canReport($user),
            'restrictedDetail' => in_array($role, [RoleName::Coordinator, RoleName::Guardian], true) || $service->canReport($user),
            'years' => SchoolYear::available()->orderByDesc('year')->get(),
        ];
    }

    public function index(SupportFilterRequest $request, StudentSupportService $service): View
    {
        $u = $request->user();
        abort_unless($service->coordinator($u) || $service->canWork($u) || $service->canReport($u) || $u->activeRole() === RoleName::Guardian, 403);
        $f = $request->validated();
        $q = $service->records($u)->with('student', 'section.grade', 'author');
        foreach (['student_id', 'section_id', 'kind', 'status', 'dimension'] as $field) {
            $q->when($f[$field] ?? null, fn (Builder $q, $value) => $q->where($field, $value));
        }
        $q->when($f['school_year_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('section', fn (Builder $s) => $s->where('school_year_id', $id)))->when($service->canWork($u) ? ($f['q'] ?? null) : null, fn (Builder $q, $term) => $q->where('title', 'like', '%'.$term.'%'));
        $records = $q->latest('occurred_on')->latest('id')->paginate(15)->withQueryString();
        foreach ($records as $record) {
            app(AuditService::class)->record('support.record_view', $record, [], $u->id);
        }

        return view('support.index', $this->options($u, $service) + compact('records', 'f'));
    }

    public function form(Request $request, StudentSupportService $service, ?SupportRecord $record = null): View
    {
        $u = $request->user();
        abort_unless($record ? $service->canEdit($u, $record) : $service->canCreate($u), 403);

        return view('support.form', $this->options($u, $service) + ['record' => $record ?? new SupportRecord]);
    }

    public function save(SaveSupportRecordRequest $request, StudentSupportService $service, ?SupportRecord $record = null): RedirectResponse
    {
        $saved = $service->save($request->validated(), $request->user(), $record);
        if (! $service->records($request->user())->whereKey($saved->id)->exists()) {
            return to_route('support.index')->with('status', 'Caso reservado remitido a Coordinación de tutoría.');
        }

        return to_route('support.show', $saved)->with('status', 'Registro guardado y auditado.');
    }

    public function show(Request $request, SupportRecord $record, StudentSupportService $service): View
    {
        abort_unless($service->records($request->user())->whereKey($record->id)->exists(), 403);
        $record->load('student', 'section.grade', 'author');
        $options = $this->options($request->user(), $service);
        if ($record->kind === 'case' && $service->coordinator($request->user())) {
            $options['restrictedDetail'] = false;
        }
        if (! $options['restrictedDetail']) {
            $record->load('followUps.author');
        }
        app(AuditService::class)->record('support.record_view', $record);

        return view('support.show', $options + ['record' => $record, 'editable' => $service->canEdit($request->user(), $record), 'canFollow' => $service->canFollow($request->user(), $record)]);
    }

    public function followUp(Request $request, SupportRecord $record, StudentSupportService $service): RedirectResponse
    {
        abort_unless($service->canFollow($request->user(), $record), 403);
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:5000'], 'next_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today']]);
        DB::transaction(function () use ($record, $data, $request, $service): void {
            $record = SupportRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless($service->canFollow($request->user(), $record), 403);
            if ($record->status === 'closed') {
                throw ValidationException::withMessages(['note' => 'Reabre el registro antes de añadir seguimiento.']);
            }
            $follow = $record->followUps()->create($data + ['user_id' => $request->user()->id]);
            if ($service->canEdit($request->user(), $record)) {
                $record->update(['status' => 'following', 'due_on' => $data['next_on'] ?? $record->due_on, 'revision' => $record->revision + 1]);
            } else {
                $record->increment('revision');
            }
            app(AuditService::class)->record('support.follow_up_saved', $follow, ['record_id' => $record->id]);
        });

        return back()->with('status', 'Seguimiento registrado.');
    }

    public function profile(Request $request, Student $student, StudentSupportService $service): View|RedirectResponse
    {
        $data = $request->validate(['school_year_id' => ['required', 'integer', 'exists:school_years,id']]);
        $year = (int) $data['school_year_id'];
        $profile = $service->profile($student, $year, $request->user());
        abort_unless($student->enrollments()->where('school_year_id', $year)->when(! $service->coordinator($request->user()), fn (Builder $q) => $q->whereIn('section_id', app(AccessScope::class)->sections($request->user())->select('sections.id')))->exists(), 403);
        if ($request->isMethod('put')) {
            abort_if(SchoolYear::findOrFail($year)->closed_at, 403);
            $input = $request->validate(['family_context' => ['nullable', 'string', 'max:5000'], 'health_context' => ['nullable', 'string', 'max:5000'], 'interests' => ['nullable', 'string', 'max:3000'], 'support_needs' => ['nullable', 'string', 'max:5000'], 'revision' => ['required', 'integer', 'min:0']]);
            DB::transaction(function () use ($student, $year, $input, $request): void {
                Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
                $profile = SupportProfile::firstOrNew(['student_id' => $student->id, 'school_year_id' => $year]);
                if ((int) $input['revision'] !== (int) ($profile->revision ?? 0)) {
                    throw ValidationException::withMessages(['revision' => 'La ficha cambió. Recarga antes de guardar.']);
                }
                $input['revision'] = ($profile->revision ?? 0) + 1;
                $profile->fill($input + ['updated_by' => $request->user()->id])->save();
                app(AuditService::class)->record('support.profile_saved', $profile);
            });

            return back()->with('status', 'Ficha tutorial guardada como información reservada.');
        }
        app(AuditService::class)->record('support.profile_view', $student, ['school_year_id' => $year]);

        return view('support.profile', $this->options($request->user(), $service) + ['student' => $student, 'profile' => $profile ?? new SupportProfile, 'yearId' => $year, 'risk' => $service->risk($student, $year)]);
    }

    public function sessions(Request $request, StudentSupportService $service): View
    {
        abort_unless($service->coordinator($request->user()) || ($request->user()->activeRole() === RoleName::Tutor && $service->canWork($request->user())), 403);
        $q = TutoringSession::with('section.grade', 'section.schoolYear', 'author')->whereIn('section_id', app(AccessScope::class)->sections($request->user())->select('sections.id'));
        $sessions = $q->latest('scheduled_on')->paginate(15);

        return view('support.sessions', $this->options($request->user(), $service) + compact('sessions'));
    }

    public function saveSession(Request $request, StudentSupportService $service, ?TutoringSession $session = null): RedirectResponse
    {
        $u = $request->user();
        abort_unless($service->canWork($u) && ($service->coordinator($u) || $u->activeRole() === RoleName::Tutor), 403);
        $data = $request->validate(['section_id' => ['required', 'integer', 'exists:sections,id'], 'topic' => ['required', 'string', 'max:160'], 'scheduled_on' => ['required', 'date_format:Y-m-d'], 'objectives' => ['required', 'string', 'max:5000'], 'outcomes' => ['nullable', 'string', 'max:5000'], 'status' => ['required', Rule::in(['planned', 'completed', 'cancelled'])], 'attendees' => ['nullable', 'array', 'max:300'], 'attendees.*' => ['required', 'integer', 'distinct', 'exists:students,id'], 'dimensions' => ['nullable', 'array', 'max:3'], 'dimensions.*' => ['required', 'distinct', Rule::in(array_keys(StudentSupportService::DIMENSIONS))], 'materials' => ['nullable', 'array:guide,exam'], 'materials.*' => ['nullable', 'file', 'mimes:pdf', 'extensions:pdf', 'max:5120'], 'share_guide' => ['nullable', 'boolean'], 'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5120'], 'revision' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($request, $u, $data, $session): void {
            $section = Section::with('schoolYear')->whereKey($data['section_id'])->lockForUpdate()->firstOrFail();
            abort_unless(app(AccessScope::class)->sections($u)->whereKey($section->id)->exists(), 403);
            if ($session) {
                $session = TutoringSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
                abort_unless($session->section_id === $section->id, 403);
                if ($session->scheduled_on->toDateString() !== $data['scheduled_on']
                    && DB::table('student_tutoring_activities')->where('tutoring_session_id', $session->id)->exists()) {
                    throw ValidationException::withMessages(['scheduled_on' => 'La sesión tiene actividades de sala publicadas. Conserva su fecha o cancela la sesión y programa una nueva.']);
                }
            }
            if ((int) $data['revision'] !== (int) ($session?->revision ?? 0)) {
                throw ValidationException::withMessages(['revision' => 'La sesión cambió. Recarga la pantalla.']);
            }
            if ($section->schoolYear->closed_at || $data['scheduled_on'] < $section->schoolYear->starts_on->toDateString() || $data['scheduled_on'] > $section->schoolYear->ends_on->toDateString()) {
                throw ValidationException::withMessages(['scheduled_on' => 'La fecha debe estar dentro del año escolar.']);
            }
            if ($data['status'] === 'completed' && ($data['scheduled_on'] > now()->toDateString() || empty($data['outcomes']))) {
                throw ValidationException::withMessages(['outcomes' => 'Una sesión realizada requiere resultados y fecha hasta hoy.']);
            }
            $attendees = $data['attendees'] ?? [];
            $valid = Enrollment::where('section_id', $section->id)->whereDate('starts_on', '<=', $data['scheduled_on'])->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $data['scheduled_on']))->pluck('student_id')->all();
            if (array_diff($attendees, $valid) || ($data['status'] !== 'completed' && count($attendees))) {
                throw ValidationException::withMessages(['attendees' => 'Solo registra asistentes matriculados en la sección y una sesión realizada.']);
            }
            $data['attendees'] = $attendees;
            $data['revision'] = ($session?->revision ?? 0) + 1;
            $existingMaterials = $session?->materials ?? [];
            $uploads = array_filter($data['materials'] ?? []);
            if (count($existingMaterials) + count($uploads) > 20) {
                throw ValidationException::withMessages(['materials' => 'La sesión admite hasta veinte versiones de guías y evaluaciones.']);
            }
            unset($data['evidence'], $data['materials'], $data['share_guide']);
            $session ??= new TutoringSession(['user_id' => $u->id]);
            $session->fill($data)->save();
            if ($request->hasFile('evidence')) {
                $session->update(['evidence_path' => $request->file('evidence')->store('tutoring-evidence', 'local'), 'evidence_mime' => $request->file('evidence')->getMimeType()]);
            }
            foreach ($uploads as $kind => $file) {
                $existingMaterials[] = [
                    'kind' => $kind,
                    'path' => $file->store('tutoring-materials', 'local'),
                    'user_id' => $u->id,
                    'date' => now()->toDateTimeString(),
                    'shared' => $kind === 'guide' && $request->boolean('share_guide'),
                ];
            }
            if ($uploads !== []) {
                $session->update(['materials' => $existingMaterials]);
            }
            app(AuditService::class)->record('support.group_session_saved', $session, ['status' => $session->status, 'dimensions' => $session->dimensions, 'materials_added' => count($uploads)]);
        });

        return to_route('support.sessions')->with('status', 'Sesión guardada y auditada.');
    }

    public function sessionEvidence(Request $request, TutoringSession $session, StudentSupportService $service): StreamedResponse
    {
        abort_unless($service->canWork($request->user()) && ($service->coordinator($request->user()) || $request->user()->activeRole() === RoleName::Tutor)
            && $service->sections($request->user())->whereKey($session->section_id)->exists(), 403);
        abort_unless($session->evidence_path, 404);
        app(AuditService::class)->record('support.evidence_download', $session);

        return Storage::disk('local')->download($session->evidence_path, 'evidencia.'.match ($session->evidence_mime) {
            'application/pdf' => 'pdf','image/png' => 'png',default => 'jpg'
        });
    }

    public function sessionMaterial(Request $request, TutoringSession $session, int $version, StudentSupportService $service): StreamedResponse
    {
        abort_unless($service->canWork($request->user()) && ($service->coordinator($request->user()) || $request->user()->activeRole() === RoleName::Tutor)
            && $service->sections($request->user())->whereKey($session->section_id)->exists(), 403);
        $material = $session->materials[$version - 1] ?? null;
        abort_unless($material && Storage::disk('local')->exists($material['path']), 404);
        app(AuditService::class)->record('support.material_download', $session, ['version' => $version]);

        return Storage::disk('local')->download($material['path'], ($material['kind'] === 'guide' ? 'guia-' : 'evaluacion-').$version.'.pdf');
    }

    public function messages(Request $request, StudentSupportService $service): View|RedirectResponse
    {
        $u = $request->user();
        abort_unless(in_array($u->activeRole(), [RoleName::Tutor, RoleName::Guardian], true), 403);
        abort_unless($u->permits($u->activeRole() === RoleName::Tutor ? 'communications.send' : 'communications.respond'), 403);
        $data = $request->validate(['student_id' => ['nullable', 'integer', 'exists:students,id'], 'tutor_id' => ['nullable', 'integer', 'exists:users,id'], 'guardian_id' => ['nullable', 'integer', 'exists:users,id']]);
        abort_if($u->activeRole() === RoleName::Guardian && isset($data['guardian_id']) && (int) $data['guardian_id'] !== $u->id, 403);
        abort_if($u->activeRole() === RoleName::Tutor && isset($data['tutor_id']) && (int) $data['tutor_id'] !== $u->id, 403);
        $student = isset($data['student_id']) ? Student::findOrFail($data['student_id']) : null;
        $messages = collect();
        $contacts = collect();
        $conversation = null;
        if ($student) {
            abort_unless($service->students($u)->whereKey($student->id)->exists(), 403);
            if ($u->activeRole() === RoleName::Tutor) {
                $contacts = $student->guardians()->where('guardian_student.is_active', true)->whereNotNull('guardian_student.verified_at')->where('users.is_active', true)->get();
            } else {
                $contacts = User::where('is_active', true)->whereHas('roles', fn (Builder $q) => $q->where('name', 'tutor'))->whereHas('tutoredSections', fn (Builder $q) => $q->where('tutor_assignments.is_active', true)->whereIn('sections.id', $student->enrollments()->where('status', 'active')->select('section_id')))->get();
            }
            $tutor = $u->activeRole() === RoleName::Tutor ? $u : User::find($data['tutor_id'] ?? 0);
            $guardian = $u->activeRole() === RoleName::Guardian ? $u : User::find($data['guardian_id'] ?? 0);
            if ($tutor && $guardian) {
                abort_unless($service->messageAllowed($u, $student, $tutor, $guardian), 403);
                $conversation = ['student_id' => $student->id, 'tutor_id' => $tutor->id, 'guardian_id' => $guardian->id];
                if ($request->isMethod('post')) {
                    $body = $request->validate(['body' => ['required', 'string', 'min:1', 'max:2000']]);
                    $message = FamilyMessage::create($conversation + ['sender_id' => $u->id, 'body' => $body['body']]);
                    app(AuditService::class)->record('family.message_sent', $message);
                    $recipient = $u->id === $tutor->id ? $guardian : $tutor;
                    $recipient->notify(new SchoolNotification('Nuevo mensaje privado', 'Tienes un mensaje para revisar en el portal.', route('support.messages', $conversation), ['kind' => 'family_message', 'student_id' => $student->id, 'message_id' => $message->id]));

                    return to_route('support.messages', $conversation)->with('status', 'Mensaje enviado en el portal.');
                }
                FamilyMessage::where($conversation)->where('sender_id', '!=', $u->id)->whereNull('read_at')->update(['read_at' => now()]);
                $messages = FamilyMessage::where($conversation)->with('sender')->latest('id')->paginate(30)->withQueryString();
                app(AuditService::class)->record('family.conversation_view', $student, ['tutor_id' => $tutor->id, 'guardian_id' => $guardian->id]);
            } elseif ($request->isMethod('post')) {
                abort(422, 'Selecciona una conversación.');
            }
        } elseif ($request->isMethod('post')) {
            abort(422, 'Selecciona un estudiante.');
        }

        return view('support.messages', $this->options($u, $service) + compact('student', 'contacts', 'conversation', 'messages'));
    }
}
