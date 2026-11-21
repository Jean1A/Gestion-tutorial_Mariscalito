@extends('layouts.app')
@section('title', 'Estudiante')
@section('content')
@can('create', \App\Models\Student::class)
    <x-back-link :href="route('students.index')">Volver a Estudiantes</x-back-link>
@else
    <x-back-link/>
@endcan
<div class="card"><div class="card-body">
    <h1 class="h3">{{ $student->full_name }}</h1>
    <dl class="row mb-0">
        <dt class="col-sm-3">DNI</dt><dd class="col-sm-9">{{ $student->dni }}</dd>
        <dt class="col-sm-3">Estado</dt><dd class="col-sm-9">{{ $student->is_active ? 'Activo' : 'Inactivo' }}</dd>
    </dl>
</div></div>
<div class="row g-3 my-2"><div class="col-md-4"><div class="card"><div class="card-body"><h2 class="h6">Asistencia de hoy</h2><strong>{{ $today?->status?->label() ?? 'Sin registro' }}</strong>@if($today?->arrival_time)<p class="mb-0">Ingreso: {{ substr($today->arrival_time,0,5) }}</p>@endif</div></div></div><div class="col-md-4"><div class="card"><div class="card-body"><h2 class="h6">Inasistencia injustificada acumulada</h2><strong>{{ $summary['percentage'] }} %</strong><p class="mb-0">{{ $summary['unjustified_absences'] }} de {{ $summary['registered_days'] }} días lectivos registrados</p></div></div></div><div class="col-md-4"><div class="card"><div class="card-body"><h2 class="h6">Alertas activas</h2><strong>{{ $alerts->count() }}</strong></div></div></div></div>
<h2 class="h5 mt-4">Historial de asistencia</h2>
<form method="get" class="row g-2 mb-3"><div class="col-md-3"><label for="school_year_id" class="form-label">Año</label><select id="school_year_id" name="school_year_id" class="form-select">@foreach($years as $year)<option value="{{ $year->id }}" @selected($year->id==$yearId)>{{ $year->year }}</option>@endforeach</select></div><div class="col-md-3"><label for="period_id" class="form-label">Período</label><select id="period_id" name="period_id" class="form-select"><option value="">Todo el año</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(request('period_id')==$period->id)>{{ $period->name }}</option>@endforeach</select></div><div class="col-md-2"><label for="from" class="form-label">Desde</label><input id="from" name="from" type="date" class="form-control" value="{{ request('from') }}"></div><div class="col-md-2"><label for="to" class="form-label">Hasta</label><input id="to" name="to" type="date" class="form-control" value="{{ request('to') }}"></div><div class="col-md-2 align-self-end"><button class="btn btn-outline-primary">Filtrar</button></div></form>
<p>En el intervalo seleccionado: {{ $filteredSummary['percentage'] }} % de faltas injustificadas.</p><div class="card table-responsive"><table class="table mb-0"><thead><tr><th>Fecha</th><th>Estado</th><th>Hora de ingreso</th></tr></thead><tbody>@forelse($records as $record)<tr><td>{{ $record->session->schoolDay->date->format('d/m/Y') }}</td><td>{{ $record->status->label() }}</td><td>{{ $record->arrival_time ? substr($record->arrival_time,0,5):'—' }}</td></tr>@empty<tr><td colspan="3">No hay asistencia registrada para este filtro.</td></tr>@endforelse</tbody></table></div><div class="mt-3">{{ $records->links() }}</div>
@endsection
