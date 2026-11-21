@extends(auth()->user()->activeRole()===\App\Enums\RoleName::Guardian?'layouts.guardian':'layouts.app')
@section('title','Historial de asistencia')
@section('content')
@can('create', \App\Models\Student::class)
    <x-back-link :href="route('students.index')">Volver a Estudiantes</x-back-link>
@else
    <x-back-link/>
@endcan
<h1>{{ $student->full_name }}</h1><p>Historial de asistencia por curso. Los registros no equivalen a días únicos ni a personas distintas.</p>
<form class="portal-filters" method="get"><div><label for="year">Año</label><select class="form-select" id="year" name="school_year_id">@foreach($years as $year)<option value="{{ $year->id }}" @selected($yearId==$year->id)>{{ $year->year }}</option>@endforeach</select></div><div><label for="period">Período</label><select class="form-select" id="period" name="period_id"><option value="">Todos</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(request('period_id')==$period->id)>{{ $period->name }}</option>@endforeach</select></div><div><label for="from">Desde</label><input class="form-control" id="from" name="from" type="date" value="{{ request('from') }}"></div><div><label for="to">Hasta</label><input class="form-control" id="to" name="to" type="date" value="{{ request('to') }}"></div><button class="btn btn-primary">Filtrar</button></form>
<section class="portal-panel"><p>{{ $summary['registered_sessions'] }} registros · {{ $summary['unjustified'] }} faltas injustificadas · {{ $summary['percentage'] }} % en el intervalo seleccionado</p><div class="table-responsive"><table class="portal-table"><thead><tr><th>Fecha</th><th>Curso</th><th>Estado</th><th>Hora</th></tr></thead><tbody>@forelse($records as $record)<tr><td>{{ $record->session->schoolDay->date->format('d/m/Y') }}</td><td>{{ $record->session->offering->course->name }}</td><td>{{ $record->status->label() }}</td><td>{{ $record->arrival_time?:'—' }}</td></tr>@empty<tr><td colspan="4">No hay registros.</td></tr>@endforelse</tbody></table></div>{{ $records->links() }}</section>
@endsection
