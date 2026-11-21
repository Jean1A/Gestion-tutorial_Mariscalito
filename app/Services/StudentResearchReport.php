<?php

namespace App\Services;

use App\Models\AcademicPeriod;
use App\Models\CourseResult;
use App\Models\SupportRecord;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StudentResearchReport
{
    public function __construct(private StudentTutoringService $tutoring, private TutorCoordinationService $coordination) {}

    public function data(array $filters, bool $pairExport): array
    {
        $sections = $this->coordination->sections($filters)->select('sections.id');
        $from = $filters['from'].' 00:00:00';
        $to = $filters['to'].' 23:59:59';
        $activities = $this->tutoring->activities()->whereIn('ts.section_id', $sections)->whereBetween('a.opens_at', [$from, $to]);
        $research = $this->researchResponses($activities, $filters['school_year_id'], $to);
        $minimum = config('student_tutoring.research_minimum');

        if ($pairExport) {
            $rows = [];
            foreach (['personal', 'climate', 'study'] as $key) {
                $pairs = $this->pairs($research->get($key, collect()));
                if ($pairs->count() < $minimum) {
                    continue;
                }
                foreach ($pairs as [$pre, $post]) {
                    $code = substr(hash_hmac('sha256', 'thesis:'.$filters['school_year_id'].':'.$pre->student_id, config('app.key')), 0, 24);
                    $before = $this->scores($pre);
                    $after = $this->scores($post);
                    foreach ($before as $scale => $score) {
                        $rows[] = [$code, config('student_tutoring.instruments.'.$key.'.title'), $pre->version, $scale, round($score, 3), round($after[$scale], 3), round($after[$scale] - $score, 3)];
                    }
                }
            }

            return ['headers' => ['Código de estudio', 'Instrumento', 'Versión', 'Indicador', 'Pre (1–5)', 'Post (1–5)', 'Cambio'], 'rows' => $rows];
        }

        $rows = $this->operationRows($filters, $activities, $from, $to);
        $sus = $research->get('sus', collect())->unique('student_id')->map($this->scores(...));
        foreach (['Usabilidad SUS (0–100)', 'Satisfacción (1–5)'] as $metric) {
            $rows[] = ['Uso del sistema', $metric, $this->average($sus, fn (array $scores): float => $scores[$metric]), $sus->count(), 'Encuesta voluntaria. Un envío por estudiante.'];
        }
        foreach (['personal', 'climate', 'study'] as $key) {
            $instrument = config('student_tutoring.instruments.'.$key);
            $responses = $research->get($key, collect());
            $pairs = $this->pairs($responses);
            $rows[] = [$instrument['dimension'], $instrument['title'].' · pre-test', $responses->where('phase', 'pre')->unique('student_id')->count(), '', 'Estudiantes con respuesta inicial.'];
            $rows[] = [$instrument['dimension'], $instrument['title'].' · post-test', $responses->where('phase', 'post')->unique('student_id')->count(), '', 'Estudiantes con respuesta final.'];
            $scores = $pairs->map(fn (array $pair): array => [$this->scores($pair[0]), $this->scores($pair[1])]);
            foreach (array_keys($instrument['scales']) as $metric) {
                $source = 'Encuesta piloto v'.config('student_tutoring.version').'. Solo pares de la misma persona.';
                $rows[] = [$instrument['dimension'], $metric.' · pre (1–5)', $this->average($scores, fn (array $pair): float => $pair[0][$metric]), $pairs->count(), $source];
                $rows[] = [$instrument['dimension'], $metric.' · post (1–5)', $this->average($scores, fn (array $pair): float => $pair[1][$metric]), $pairs->count(), $source];
                $rows[] = [$instrument['dimension'], $metric.' · cambio', $this->average($scores, fn (array $pair): float => $pair[1][$metric] - $pair[0][$metric]), $pairs->count(), $source];
            }
        }

        return ['headers' => ['Dimensión', 'Indicador', 'Valor', 'Base (n)', 'Fuente y criterio'], 'rows' => $rows];
    }

    private function researchResponses(Builder $activities, int $yearId, string $to): Collection
    {
        $authorizedActivities = (clone $activities)->where('a.phase', '!=', 'practice')->where('a.version', config('student_tutoring.version'))->select('a.id');

        return DB::table('student_tutoring_responses as r')
            ->join('student_tutoring_activities as a', 'a.id', '=', 'r.activity_id')
            ->join('enrollments as e', 'e.id', '=', 'r.enrollment_id')
            ->join('student_research_permissions as p', fn ($join) => $join
                ->on('p.student_id', '=', 'e.student_id')->on('p.school_year_id', '=', 'e.school_year_id'))
            ->whereIn('r.activity_id', $authorizedActivities)->where('e.school_year_id', $yearId)
            ->where('p.authorized', true)->whereNotNull('r.assented_at')->where('r.created_at', '<=', $to)
            ->orderBy('r.created_at')->orderBy('r.id')
            ->get(['r.payload', 'r.created_at', 'a.phase', 'a.instrument', 'a.version', 'e.student_id'])->groupBy('instrument');
    }

    /** @return Collection<int, array{object, object}> */
    private function pairs(Collection $responses): Collection
    {
        return $responses->groupBy('student_id')->map(function (Collection $student): ?array {
            $pre = $student->firstWhere('phase', 'pre');
            $post = $pre ? $student->first(fn (object $row): bool => $row->phase === 'post' && $row->created_at > $pre->created_at) : null;

            return $post ? [$pre, $post] : null;
        })->filter()->values();
    }

    private function scores(object $response): array
    {
        return $this->tutoring->scaleScores($response->instrument, $this->tutoring->readPayload($response)['answers']);
    }

    private function average(Collection $rows, callable $value): float|string
    {
        return $rows->count() >= config('student_tutoring.research_minimum') ? round($rows->avg($value), 2) : '—';
    }

    private function operationRows(array $filters, Builder $activities, string $from, string $to): array
    {
        $sections = $this->coordination->sections($filters)->select('sections.id');
        $accesses = DB::table('student_tutoring_accesses as log')->join('enrollments as e', 'e.id', '=', 'log.enrollment_id')
            ->whereIn('log.tutoring_session_id', (clone $activities)->select('ts.id'))
            ->whereBetween('log.accessed_at', [$from, $to]);
        $closed = (clone $activities)->where('a.phase', 'practice')->where('a.closes_at', '<=', min($to, now()->toDateTimeString()));
        $responses = DB::table('student_tutoring_responses')->whereIn('activity_id', (clone $closed)->select('a.id'));
        $expected = $closed->join('enrollments as e', function ($join): void {
            $join->on('e.section_id', '=', 'ts.section_id')->whereColumn('e.starts_on', '<=', 'ts.scheduled_on')
                ->where(fn ($end) => $end->whereNull('e.ends_on')->orWhereColumn('e.ends_on', '>=', 'ts.scheduled_on'));
        })->where(fn ($enrollment) => $enrollment->where('e.status', 'active')->orWhereNotNull('e.ends_on'))->count();
        $completed = $responses->count();
        $entries = $this->tutoring->entries()->whereIn('origin.section_id', $sections)->whereBetween('entry.created_at', [$from, $to]);
        $requests = (clone $entries)->where('entry.kind', 'request');
        $requestCount = (clone $requests)->count();
        $answeredRequests = (clone $requests)->where('entry.attended_at', '<=', $to)->select('entry.created_at', 'entry.attended_at')->get();
        $attended = $answeredRequests->count();
        $referred = (clone $requests)->where('entry.referred_at', '<=', $to)->count();
        $goals = (clone $entries)->where('entry.kind', 'goal')
            ->when($filters['period_id'], fn (Builder $query, int $id) => $query->where('entry.academic_period_id', $id));
        $progress = DB::table('student_goal_updates as progress')->whereIn('progress.entry_id', (clone $goals)->select('entry.id'))
            ->where('progress.created_at', '<=', $to)->selectRaw('MAX(progress.id) as id')->groupBy('progress.entry_id');
        $updates = DB::table('student_goal_updates')->whereIn('id', $progress);
        $periods = AcademicPeriod::where('school_year_id', $filters['school_year_id'])
            ->where('starts_on', '<=', $filters['to'])->where('ends_on', '>=', $filters['from'])
            ->when($filters['period_id'], fn ($query, int $id) => $query->whereKey($id));
        $grades = CourseResult::where('is_published', true)->whereIn('academic_period_id', $periods->select('id'))
            ->whereHas('enrollment', fn ($query) => $query->whereIn('section_id', $sections))
            ->selectRaw('achievement, COUNT(*) AS total')->groupBy('achievement')->pluck('total', 'achievement');
        $individual = SupportRecord::whereIn('section_id', $sections)->where('dimension', 'personal')
            ->whereIn('kind', ['interview', 'case'])->whereBetween('occurred_on', [$filters['from'], $filters['to']])->count();
        $forum = DB::table('student_tutoring_responses')->whereIn('activity_id', (clone $activities)->where('a.instrument', 'forum')->select('a.id'))
            ->whereBetween('student_tutoring_responses.created_at', [$from, $to]);

        return [
            ['Uso del sistema', 'Accesos en horario de tutoría', $accesses->count(), '', 'Una entrada por estudiante y sesión mientras hay una actividad disponible.'],
            ['Uso del sistema', 'Estudiantes con acceso en horario', (clone $accesses)->distinct()->count('e.student_id'), '', 'Estudiantes únicos en el intervalo.'],
            ['Uso del sistema', 'Actividades guiadas completadas', $completed, $expected, 'Envíos registrados en actividades cuyo horario terminó.'],
            ['Uso del sistema', 'Cumplimiento de actividades guiadas (%)', $expected ? round($completed * 100 / $expected, 2) : '—', $expected, 'Envíos / asignaciones esperadas según matrícula en la fecha.'],
            ['Personal', 'Atenciones individuales registradas', $individual, '', 'Entrevistas y casos de dimensión personal en Seguimiento.'],
            ['Personal', 'Entradas de bitácora con respuesta', (clone $entries)->where('entry.kind', 'journal')->where('entry.attended_at', '<=', $to)->count(), (clone $entries)->where('entry.kind', 'journal')->count(), 'Solo conteos de la bitácora.'],
            ['Social', 'Solicitudes del buzón recibidas', $requestCount, '', 'Solicitudes creadas durante el intervalo.'],
            ['Social', 'Solicitudes atendidas', $attended, $requestCount, 'Primera respuesta registrada antes del fin del intervalo.'],
            ['Social', 'Solicitudes derivadas', $referred, $requestCount, 'Derivaciones para atención reservada registradas antes del fin del intervalo.'],
            ['Social', 'Tasa de atención del buzón (%)', $requestCount ? round($attended * 100 / $requestCount, 2) : '—', $requestCount, 'Solicitudes atendidas / solicitudes recibidas.'],
            ['Social', 'Tiempo medio hasta la primera respuesta (horas)', $this->average($answeredRequests, fn (object $row): float => Carbon::parse($row->created_at)->diffInHours($row->attended_at)), $attended, 'Horas transcurridas entre el envío y la primera respuesta.'],
            ['Social', 'Participaciones en foros', (clone $forum)->count(), '', 'Envíos de reflexión, con o sin publicación.'],
            ['Social', 'Estudiantes que participaron en foros', $forum->join('enrollments as e', 'e.id', '=', 'student_tutoring_responses.enrollment_id')->distinct()->count('e.student_id'), '', 'Estudiantes únicos con un envío.'],
            ['Aprendizajes', 'Metas de estudio registradas', $goals->count(), '', 'Planificador de metas.'],
            ['Aprendizajes', 'Metas con avances registrados', (clone $updates)->count(), $goals->count(), 'Último avance de cada meta al final del intervalo.'],
            ['Aprendizajes', 'Metas alcanzadas', $updates->where('progress', 100)->count(), $goals->count(), 'Último avance informado de 100 %.'],
            ...$grades->map(fn (int $count, string $grade): array => ['Aprendizajes', 'Logros '.$grade, $count, $grades->sum(), 'Resultados publicados de cursos con períodos que coinciden con el filtro.'])->values()->all(),
        ];
    }
}
