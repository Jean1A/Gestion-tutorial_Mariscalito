@extends('layouts.coordination')
@section('title', 'Promociones y traslados')
@section('content')
<x-back-link/>
<h1>Promociones y traslados</h1>
<p>Revisa las matrículas de origen y selecciona quiénes integrarán el lote. Puedes cambiar el destino individual para una permanencia o excluir al estudiante si su decisión sigue pendiente.</p>
<section class="portal-panel">
    <form method="get" action="{{ route('coordination.movements') }}">
        <label class="form-label" for="source_id">Sección de origen</label>
        <select class="form-select mb-2" id="source_id" name="source_id" required>
            <option value="">Selecciona una sección</option>
            @foreach($sections as $section)
                <option value="{{ $section->id }}" @selected($source?->id === $section->id)>{{ $section->schoolYear->year }} · {{ $section->grade->educationLevel->name }} · {{ $section->grade->name }} {{ $section->name }}</option>
            @endforeach
        </select>
        <button class="btn btn-outline-primary">Revisar lista</button>
    </form>
</section>
@if($source && $roster->isNotEmpty())
    <form class="portal-panel" data-enrollment-batch method="post" action="{{ route('coordination.movements.store') }}">
        @csrf
        <input type="hidden" name="source_id" value="{{ $source->id }}">
        <label class="form-label" for="destination_id">Destino general</label>
        <select class="form-select mb-3" id="destination_id" name="destination_id" required>
            <option value="">Selecciona una sección</option>
            @foreach($destinations as $section)
                <option value="{{ $section->id }}" @selected(old('destination_id') == $section->id)>{{ $section->schoolYear->year }} · {{ $section->grade->name }} {{ $section->name }}</option>
            @endforeach
        </select>
        <label class="form-label" for="starts_on">Fecha efectiva</label>
        <input class="form-control mb-3" type="date" name="starts_on" id="starts_on" value="{{ old('starts_on') }}" required>
        <label class="form-label" for="movement-reason">Motivo y referencia de la decisión</label>
        <textarea class="form-control mb-3" name="reason" id="movement-reason" minlength="8" maxlength="500" required>{{ old('reason') }}</textarea>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th><label><input type="checkbox" data-select-batch> Incluir a todos</label></th><th>Estudiante</th><th>Destino individual</th></tr></thead>
                <tbody>
                    @foreach($roster as $enrollment)
                        <tr>
                            <td><input type="checkbox" name="enrollments[]" value="{{ $enrollment->id }}" aria-label="Incluir a {{ $enrollment->student->full_name }}" @checked(in_array($enrollment->id, old('enrollments', [])))></td>
                            <td>{{ $enrollment->student->full_name }}</td>
                            <td><select class="form-select" name="overrides[{{ $enrollment->id }}]" aria-label="Destino de {{ $enrollment->student->full_name }}">
                                <option value="">Usar destino general</option>
                                @foreach($destinations as $section)
                                    <option value="{{ $section->id }}" @selected(old('overrides.'.$enrollment->id) == $section->id)>{{ $section->schoolYear->year }} · {{ $section->grade->name }} {{ $section->name }}</option>
                                @endforeach
                            </select></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <button class="btn btn-primary">Confirmar movimientos seleccionados</button>
    </form>
@elseif($source)
    <p class="portal-panel">La sección de origen no tiene matrículas activas para mover.</p>
@endif
@endsection
