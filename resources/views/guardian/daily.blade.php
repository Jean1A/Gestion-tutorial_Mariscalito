@extends('layouts.guardian')
@section('title', 'Asistencia diaria')
@section('content')
<x-back-link/>
<h1>Asistencia diaria</h1>
<x-child-banner :child="$child" :enrollment="$enrollment"/>
<section class="portal-panel">
    <p>Consulta las jornadas registradas por el auxiliar.</p>
    <form method="get" class="portal-filters">
        <div><label for="from">Desde</label><input class="form-control" type="date" name="from" id="from" value="{{ request('from') }}"></div>
        <div><label for="to">Hasta</label><input class="form-control" type="date" name="to" id="to" value="{{ request('to') }}"></div>
        <button class="btn btn-primary">Filtrar</button>
    </form>
    @if($summary['registered_days'])
        <p>{{ $summary['registered_days'] }} jornadas registradas · {{ $summary['percentage'] }} % de faltas injustificadas</p>
    @endif
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Fecha</th><th>Estado</th><th>Ingreso</th></tr></thead>
            <tbody>
                @forelse($records as $record)
                    <tr><td>{{ $record->session->schoolDay->date->format('d/m/Y') }}</td><td>{{ $record->status->label() }}</td><td>{{ $record->arrival_time ? substr($record->arrival_time, 0, 5) : '—' }}</td></tr>
                @empty
                    <tr><td colspan="3">Sin jornadas registradas para el filtro.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $records->links() }}
</section>
@endsection
