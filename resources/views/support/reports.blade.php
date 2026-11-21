@extends('layouts.coordination')
@section('title', 'Reportes del colegio')
@section('content')
<x-back-link/>
<h1>Reportes para coordinación</h1>
<p class="portal-subtitle">Consulta y descarga los indicadores escolares y los datos para la tesis.</p>
<section class="portal-panel">
    <div class="portal-panel__heading"><x-icon name="bar-chart"/><h2>Seleccionar informe</h2></div>
    <form method="get" class="portal-filters mb-0" data-coordination-filters>
        <div><label for="report-type">Informe</label><select id="report-type" name="type" class="form-select">
            @foreach($reportTypes as $value => $label)<option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>@endforeach
        </select></div>
        <div><label for="year">Año escolar</label><select id="year" name="school_year_id" class="form-select" data-coordination-year>
            @foreach($years as $year)
                <option value="{{ $year->id }}" data-start="{{ $year->starts_on->toDateString() }}" data-end="{{ $year->ends_on->toDateString() }}" @selected($filters['school_year_id'] === $year->id)>{{ $year->year }}</option>
            @endforeach
        </select></div>
        <div><label for="grade">Grado</label><select id="grade" name="grade_id" class="form-select">
            <option value="">Todos</option>
            @foreach($filterGrades as $grade)<option value="{{ $grade->id }}" @selected($filters['grade_id'] === $grade->id)>{{ $grade->educationLevel->name }} · {{ $grade->name }}</option>@endforeach
        </select></div>
        <div><label for="section">Sección</label><select id="section" name="section_id" class="form-select">
            <option value="">Todas</option>
            @foreach($filterSections as $section)<option value="{{ $section->id }}" @selected($filters['section_id'] === $section->id)>{{ $section->grade->name }} {{ $section->name }}</option>@endforeach
        </select></div>
        <div><label for="period">Período</label><select id="period" name="period_id" class="form-select">
            <option value="">Todos</option>
            @foreach($filterPeriods as $period)<option value="{{ $period->id }}" @selected($filters['period_id'] === $period->id)>{{ $period->name }}</option>@endforeach
        </select></div>
        <div><label for="from">Desde</label><input class="form-control" type="date" id="from" name="from" value="{{ $filters['from'] }}"></div>
        <div><label for="to">Hasta</label><input class="form-control" type="date" id="to" name="to" value="{{ $filters['to'] }}"></div>
        <button class="btn btn-orange">Consultar</button>
    </form>
</section>
@if($research)
    <p class="soft-note"><x-icon name="lock"/> Las encuestas se contabilizan con autorización vigente y participación voluntaria. Los promedios y los datos emparejados requieren al menos {{ config('student_tutoring.research_minimum') }} estudiantes por instrumento. Para emparejar el pre y el post, selecciona un intervalo que incluya ambas aplicaciones.</p>
@else
    <p class="soft-note">El semáforo resume señales registradas. SíseVe requiere valoración y registro manual por el personal responsable.</p>
@endif
<section class="portal-panel">
    <div class="advisor-page-heading">
        <div><h2>{{ $reportTypes[$type] }}</h2><p class="stats-caption">{{ count($rows) }} filas · {{ $filters['from'] }} a {{ $filters['to'] }}</p></div>
        <div class="d-flex flex-wrap gap-2">
            @foreach(['pdf' => 'Descargar PDF', 'xlsx' => 'Descargar Excel'] as $format => $label)
                <a class="btn btn-outline-primary" href="{{ route('school-reports.export', array_merge(request()->query(), ['format' => $format, 'type' => $type, 'school_year_id' => $filters['school_year_id']])) }}"><x-icon name="download"/> {{ $label }}</a>
            @endforeach
        </div>
    </div>
    <div class="table-responsive"><table class="portal-table">
        <thead><tr>@foreach($headers as $header)<th scope="col">{{ $header }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse($rows as $row)
                <tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($headers) }}">No hay registros para los filtros seleccionados.</td></tr>
            @endforelse
        </tbody>
    </table></div>
</section>
@endsection
