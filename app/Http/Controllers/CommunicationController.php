<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\CommunicationComposeRequest;
use App\Http\Requests\RespondCommunicationRequest;
use App\Http\Requests\SendCommunicationRequest;
use App\Models\Communication;
use App\Models\CommunicationRecipient;
use App\Models\Grade;
use App\Models\SchoolYear;
use App\Services\AccessScope;
use App\Services\AuditService;
use App\Services\CommunicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CommunicationController extends Controller
{
    public function reschedule(Request $request, Communication $communication, CommunicationService $service): RedirectResponse
    {
        $data = $request->validate(['scheduled_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:now'], 'location' => ['required', 'string', 'max:160'], 'reason' => ['required', 'string', 'min:5', 'max:500'], 'revision' => ['required', 'integer', 'min:1']]);
        $service->reschedule($communication, $data, $request->user());

        return back()->with('status', 'Citación reprogramada. Las respuestas anteriores se conservaron y se solicitará nueva confirmación.');
    }

    public function index(Request $request, AccessScope $scope, AuditService $audit)
    {
        $guardian = $request->user()->activeRole() === RoleName::Guardian;
        abort_unless($guardian ? $request->user()->permits('communications.respond') : $request->user()->permits('communications.send'), 403);
        if ($guardian) {
            $rows = CommunicationRecipient::with('communication', 'student')->where('user_id', $request->user()->id)->whereIn('student_id', $scope->students($request->user())->select('students.id'))->orderByDesc('id')->paginate(20);
        } else {
            $query = Communication::withCount('recipients')->where('user_id', $request->user()->id);
            if ($request->user()->activeRole() === RoleName::Tutor) {
                $query->whereHas('recipients', fn ($q) => $q->whereIn('student_id', $scope->students($request->user())->select('students.id')));
            }
            $rows = $query->orderByDesc('id')->paginate(20);
        }
        foreach ($rows as $row) {
            $audit->record('communication.view', $row);
        }

        return view('communications.index', compact('rows', 'guardian'));
    }

    public function create(CommunicationComposeRequest $request, AccessScope $scope)
    {
        abort_unless($request->user()->permits('communications.send'), 403);

        $sections = $scope->sections($request->user())->with('schoolYear', 'grade.educationLevel')->get();
        $prefillDni = $request->validated('student_dni');
        $prefillYearId = null;
        if ($prefillDni) {
            $student = $scope->students($request->user())->where('dni', $prefillDni)->first();
            abort_unless($student, 403);
            $prefillYearId = $student->enrollments()->whereIn('section_id', $sections->pluck('id'))->latest('starts_on')->value('school_year_id');
        }
        $years = SchoolYear::where('is_active', true)->when($request->user()->activeRole() === RoleName::Tutor, fn ($q) => $q->whereIn('id', $sections->pluck('school_year_id')))->orderByDesc('year')->get();

        return view('communications.form', ['years' => $years, 'sections' => $sections, 'grades' => $request->user()->activeRole() === RoleName::Coordinator ? Grade::with('educationLevel')->get() : collect(), 'role' => $request->user()->activeRole(), 'prefillDni' => $prefillDni, 'prefillYearId' => $prefillYearId]);
    }

    public function store(SendCommunicationRequest $request, CommunicationService $service)
    {
        $service->send($request->validated(), $request->user());

        return redirect()->route('communications.index')->with('status', 'Comunicación enviada a los apoderados autorizados.');
    }

    public function show(CommunicationRecipient $recipient, AuditService $audit)
    {
        Gate::authorize('view', $recipient);
        $recipient->load('communication', 'student');
        $audit->record('communication.view', $recipient);

        return view('communications.show', compact('recipient'));
    }

    public function read(CommunicationRecipient $recipient, AuditService $audit)
    {
        Gate::authorize('view', $recipient);
        if (! $recipient->read_at) {
            $recipient->update(['read_at' => now()]);
            $audit->record('communication.read', $recipient);
        }

        return back()->with('status', 'Lectura confirmada.');
    }

    public function respond(RespondCommunicationRequest $request, CommunicationRecipient $recipient, CommunicationService $service)
    {
        $service->respond($recipient, $request->validated(), $request->user());

        return back()->with('status', 'Respuesta registrada. Solicitar reprogramación no cambia la cita hasta que el colegio coordine contigo.');
    }

    public function recipients(Request $request, Communication $communication, AuditService $audit, AccessScope $scope)
    {
        abort_unless($request->user()->permits('communications.send') && $communication->user_id === $request->user()->id, 403);
        $query = $communication->recipients()->with('student', 'user');
        if ($request->user()->activeRole() === RoleName::Tutor) {
            $query->whereIn('student_id', $scope->students($request->user())->select('students.id'));
            abort_unless((clone $query)->exists(), 403);
        }
        $rows = $query->paginate(20);
        foreach ($rows as $row) {
            $audit->record('communication.receipt_view', $row);
        }

        return view('communications.receipts', compact('communication', 'rows'));
    }
}
