<?php

namespace App\Services;

use App\Models\CourseAttendanceAlert;
use App\Models\CourseOffering;
use App\Models\CourseResult;
use App\Models\Student;
use App\Models\SupportRecord;
use App\Models\TutoringSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SchoolReportService
{
    public const TYPES = ['attendance' => 'Asistencia por estudiante y curso', 'interventions' => 'Intervenciones y atenciones', 'sessions' => 'Sesiones de tutoría grupal', 'risk' => 'Seguimiento integral de riesgo', 'siseve' => 'Incidencias para registro manual en SíseVe'];

    public const RESEARCH_TYPES = ['research' => 'Tesis · indicadores por dimensión', 'research-pairs' => 'Tesis · datos pre y post seudonimizados'];

    /** @return array{headers:array<int,string>,rows:array<int,array<int,mixed>>} */
    public function data(string $type, array $filters): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $sections = app(TutorCoordinationService::class)->sections($filters)->select('sections.id');
        $rows = [];
        if ($type === 'attendance') {
            $headers = ['Estudiante', 'Sección', 'Curso', 'Sesiones', 'Presente', 'Tardanza', 'Justificadas', 'Injustificadas', 'Porcentaje injustificadas'];
            $groups = DB::table('course_attendance_records as r')
                ->join('enrollments as e', 'e.id', '=', 'r.enrollment_id')
                ->join('course_attendance_sessions as s', 's.id', '=', 'r.course_attendance_session_id')
                ->whereIn('s.id', app(TutorCoordinationService::class)->sessions($filters)->select('course_attendance_sessions.id'))
                ->selectRaw("e.student_id, s.course_offering_id, COUNT(*) as total, SUM(CASE WHEN r.status = 'present' THEN 1 ELSE 0 END) as present_count, SUM(CASE WHEN r.status = 'late' THEN 1 ELSE 0 END) as late_count, SUM(CASE WHEN r.status = 'justified_absence' THEN 1 ELSE 0 END) as justified_count, SUM(CASE WHEN r.status = 'unjustified_absence' THEN 1 ELSE 0 END) as unjustified_count")
                ->groupBy('e.student_id', 's.course_offering_id')->get();
            $students = Student::withTrashed()->whereIn('id', $groups->pluck('student_id'))->get()->keyBy('id');
            $courses = CourseOffering::with('course', 'section.grade')->whereIn('id', $groups->pluck('course_offering_id'))->get()->keyBy('id');
            foreach ($groups as $group) {
                $student = $students->get($group->student_id);
                $course = $courses->get($group->course_offering_id);
                $rows[] = [$student->full_name, $course->section->grade->name.' '.$course->section->name, $course->course->name, (int) $group->total, (int) $group->present_count, (int) $group->late_count, (int) $group->justified_count, (int) $group->unjustified_count, round($group->unjustified_count * 100 / $group->total, 2)];
            }
        } elseif (in_array($type, ['interventions', 'siseve'], true)) {
            $headers = ['Registro', 'Estudiante', 'Sección', 'Tipo', 'Fecha', 'Gravedad', 'Estado', 'Responsable', 'Próximo seguimiento'];
            $q = SupportRecord::select('id', 'student_id', 'section_id', 'author_id', 'kind', 'occurred_on', 'severity', 'status', 'due_on')->whereIn('section_id', $sections)->whereDate('occurred_on', '>=', $filters['from'])->whereDate('occurred_on', '<=', $filters['to'])->with('student', 'section.grade', 'author');
            if ($type === 'siseve') {
                $q->where('kind', 'incident')->where('severity', 'high');
            }
            foreach ($q->orderBy('occurred_on')->get() as $r) {
                $rows[] = [$r->id, $r->student->full_name, $r->section->grade->name.' '.$r->section->name, StudentSupportService::KINDS[$r->kind], $r->occurred_on->toDateString(), ['low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta'][$r->severity], StudentSupportService::STATUSES[$r->status], $r->kind === 'case' ? 'Coordinación de tutoría' : $r->author?->name, $r->due_on?->toDateString() ?? ''];
            }
        } elseif ($type === 'sessions') {
            $headers = ['Sección', 'Tema', 'Fecha', 'Estado', 'Asistentes registrados', 'Responsable'];
            foreach (TutoringSession::whereIn('section_id', $sections)->whereDate('scheduled_on', '>=', $filters['from'])->whereDate('scheduled_on', '<=', $filters['to'])->with('section.grade', 'author')->orderBy('scheduled_on')->get() as $s) {
                $rows[] = [$s->section->grade->name.' '.$s->section->name, $s->topic, $s->scheduled_on->toDateString(), ['planned' => 'Planificada', 'completed' => 'Realizada', 'cancelled' => 'Cancelada'][$s->status], count($s->attendees ?? []), $s->author?->name];
            }
        } else {
            $headers = ['Estudiante', 'Semáforo', 'Alertas de asistencia', 'Incidencias relevantes', 'Logros B o C publicados'];
            $students = Student::where('is_active', true)->whereHas('enrollments', fn (Builder $q) => $q->whereIn('section_id', $sections)->where('status', 'active'))->orderBy('last_name')->get();
            $attendance = CourseAttendanceAlert::where('is_active', true)->whereHas('enrollment', fn (Builder $q) => $q->whereIn('section_id', $sections))->with('enrollment')->get()->pluck('enrollment.student_id')->flip();
            $conduct = SupportRecord::whereIn('section_id', $sections)->where('kind', 'incident')->where('status', '!=', 'closed')->whereIn('severity', ['medium', 'high'])->get(['student_id', 'severity'])->groupBy('student_id');
            $low = CourseResult::where('is_published', true)->whereIn('achievement', ['B', 'C'])->whereHas('enrollment', fn (Builder $q) => $q->whereIn('section_id', $sections))->with('enrollment')->get()->pluck('enrollment.student_id')->flip();
            foreach ($students as $s) {
                $a = $attendance->has($s->id);
                $c = $conduct->has($s->id);
                $l = $low->has($s->id);
                $high = $c && $conduct->get($s->id)->contains('severity', 'high');
                $level = $high || ((int) $a + (int) $c + (int) $l) >= 2 ? 'Rojo' : ($a || $c || $l ? 'Amarillo' : 'Verde');
                $rows[] = [$s->full_name, $level, $a ? 'Sí' : 'No', $c ? 'Sí' : 'No', $l ? 'Sí' : 'No'];
            }
        }

        return compact('headers', 'rows');
    }

    public function xlsx(array $headers, iterable $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $path = tempnam(storage_path('app/private'), 'report-');
            abort_unless($path !== false, 500);
            $writer = new Writer;
            try {
                $writer->openToFile($path);
                $writer->addRow(Row::fromValues($headers));
                foreach ($rows as $row) {
                    $writer->addRow(new Row(array_map(fn (mixed $value): Cell => is_string($value) && str_starts_with($value, '=')
                        ? new StringCell($value)
                        : Cell::fromValue($value), $row)));
                }
                $writer->close();
                readfile($path);
            } finally {
                unlink($path);
            }
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @param array<int,string> $lines */
    public function pdf(array $lines): string
    {
        $wrapped = [];
        foreach ($lines as $line) {
            foreach (explode("\n", wordwrap($line, 88, "\n", true)) as $part) {
                $wrapped[] = $part;
            }
        }
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'];
        $kids = [];
        foreach (array_chunk($wrapped ?: ['Sin datos.'], 42) as $i => $chunk) {
            $page = 4 + $i * 2;
            $stream = $page + 1;
            $kids[] = $page.' 0 R';
            $cmd = "BT /F1 10 Tf 45 790 Td 17 TL\n";
            foreach ($chunk as $line) {
                $text = mb_convert_encoding($line, 'Windows-1252', 'UTF-8');
                $text = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $text);
                $cmd .= '('.$text.") Tj T*\n";
            }$cmd .= 'ET';
            $objects[$page] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents '.$stream.' 0 R >>';
            $objects[$stream] = '<< /Length '.strlen($cmd)." >>\nstream\n".$cmd."\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $n => $obj) {
            $offsets[$n] = strlen($pdf);
            $pdf .= $n." 0 obj\n".$obj."\nendobj\n";
        }$start = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$start."\n%%EOF";
    }
}
