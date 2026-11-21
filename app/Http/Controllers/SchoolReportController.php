<?php

namespace App\Http\Controllers;

use App\Http\Requests\CoordinationFilterRequest;
use App\Services\AuditService;
use App\Services\SchoolReportService;
use App\Services\StudentResearchReport;
use App\Services\TutorCoordinationService;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SchoolReportController extends Controller
{
    private function report(CoordinationFilterRequest $request, SchoolReportService $service, TutorCoordinationService $coordination): array
    {
        $reportTypes = SchoolReportService::TYPES + SchoolReportService::RESEARCH_TYPES;
        $type = $request->validate(['type' => ['nullable', Rule::in(array_keys($reportTypes))]])['type'] ?? 'attendance';
        $research = isset(SchoolReportService::RESEARCH_TYPES[$type]);
        abort_if($research && ! $request->user()->can('student-research'), 403);
        if (! $request->user()->can('student-research')) {
            $reportTypes = SchoolReportService::TYPES;
        }
        $filters = $coordination->filters($request->validated());
        app(AuditService::class)->record('school.report_view', $request->user(), ['type' => $type, 'school_year_id' => $filters['school_year_id']]);

        $results = $research ? app(StudentResearchReport::class)->data($filters, $type === 'research-pairs') : $service->data($type, $filters);

        return $coordination->options($filters) + $results + compact('type', 'reportTypes', 'research');
    }

    public function index(CoordinationFilterRequest $request, SchoolReportService $service, TutorCoordinationService $coordination): View
    {
        return view('support.reports', $this->report($request, $service, $coordination));
    }

    public function export(CoordinationFilterRequest $request, string $format, SchoolReportService $service, TutorCoordinationService $coordination): Response|StreamedResponse
    {
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);
        $data = $this->report($request, $service, $coordination);
        app(AuditService::class)->record('school.report_download', $request->user(), ['type' => $data['type'], 'format' => $format]);
        if ($format === 'pdf') {
            $lines = [config('school.name'), $data['reportTypes'][$data['type']], 'Intervalo '.$data['filters']['from'].' al '.$data['filters']['to'], 'Generado '.now()->format('d/m/Y H:i'), 'Resumen informativo de uso institucional.', ''];
            foreach ($data['rows'] as $row) {
                foreach ($data['headers'] as $i => $header) {
                    $lines[] = $header.': '.($row[$i] ?? '');
                }$lines[] = '';
            }if (! $data['rows']) {
                $lines[] = 'Sin registros para los filtros seleccionados.';
            }

            return response($service->pdf($lines), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="reporte-'.$data['type'].'.pdf"']);
        }

        return $service->xlsx($data['headers'], $data['rows'], 'reporte-'.$data['type'].'.xlsx');
    }
}
