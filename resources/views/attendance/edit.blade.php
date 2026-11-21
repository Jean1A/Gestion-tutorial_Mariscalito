@extends('layouts.app')
@section('title', 'Asistencia diaria')
@section('content')
<x-back-link :href="route('sections.show', $section)">Volver a la sección</x-back-link>
<h1>Asistencia diaria</h1>
<p class="portal-subtitle">{{ $section->grade->name }} · Sección {{ $section->name }}</p>

<section class="portal-panel">
    <form method="get" class="portal-filters">
        <div>
            <label for="attendance-date">Fecha de asistencia</label>
            <input id="attendance-date" type="date" name="date" value="{{ $date }}" max="{{ now()->toDateString() }}" class="form-control" required>
        </div>
        <button class="btn btn-outline-primary">Consultar fecha</button>
    </form>
    @if($summary->isNotEmpty())
        <p class="soft-note"><x-icon name="info-circle"/> {{ $records->count() }} registros de la jornada · {{ $summary->get('unjustified_absence', 0) }} faltas injustificadas · {{ $summary->get('late', 0) }} tardanzas</p>
    @endif
    @unless($canRecord)
        <p class="soft-note"><x-icon name="info-circle"/> El auxiliar asignado registra la jornada.</p>
    @endunless

    <form method="post" action="{{ route('attendance.store', $section) }}" data-attendance-form>
        @csrf
        <input type="hidden" name="date" value="{{ $date }}">
        <input type="hidden" name="revision" value="{{ $session?->revision ?? 0 }}">
        <fieldset @disabled(!$canRecord)>
            @if($canRecord && $roster->isNotEmpty())
                <div class="portal-filters">
                    <div><label for="base-time">Hora de ingreso común</label><input id="base-time" type="time" class="form-control" value="{{ now()->format('H:i') }}"></div>
                    <button type="button" class="btn btn-outline-primary" data-mark-present>Marcar pendientes como presentes</button>
                </div>
            @endif
            <div class="card table-responsive">
                <table class="table attendance-roster mb-0">
                    <thead><tr><th scope="col">Estudiante</th><th scope="col">Estado</th><th scope="col">Ingreso</th><th scope="col">Sustento de justificación</th></tr></thead>
                    <tbody>
                        @foreach($roster as $enrollment)
                            @php
                                $record = $records->get($enrollment->id);
                                $field = 'records.'.$loop->index.'.';
                            @endphp
                            <tr data-attendance-row>
                                <th scope="row">{{ $enrollment->student->full_name }}<input type="hidden" name="records[{{ $loop->index }}][enrollment_id]" value="{{ $enrollment->id }}"></th>
                                <td data-label="Estado">
                                    <select name="records[{{ $loop->index }}][status]" class="form-select" data-status required aria-label="Estado de {{ $enrollment->student->full_name }}">
                                        <option value="">Pendiente</option>
                                        @foreach(\App\Enums\AttendanceStatus::cases() as $status)
                                            @continue($status === \App\Enums\AttendanceStatus::JustifiedAbsence && $record?->status !== $status && auth()->user()->activeRole() !== \App\Enums\RoleName::Tutor)
                                            <option value="{{ $status->value }}" @selected(old($field.'status', $record?->status?->value) === $status->value)>{{ $status->label() }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td data-label="Ingreso"><input type="time" name="records[{{ $loop->index }}][arrival_time]" value="{{ old($field.'arrival_time', $record?->arrival_time ? substr($record->arrival_time, 0, 5) : '') }}" class="form-control" data-arrival aria-label="Hora de ingreso de {{ $enrollment->student->full_name }}"></td>
                                <td data-label="Sustento de justificación" data-wide><input name="records[{{ $loop->index }}][justification]" class="form-control" value="{{ old($field.'justification', $record?->justification) }}" maxlength="500" aria-label="Sustento de {{ $enrollment->student->full_name }}"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($roster->isEmpty())
                <p class="empty-state">No hay estudiantes en la lista para esta fecha.</p>
            @elseif($canRecord)
                <button class="btn btn-orange mt-3"><x-icon name="check-circle"/> Guardar asistencia de la sección</button>
                <p class="stats-caption">La edición se permite durante el plazo configurado. Solo el tutor aprueba o cambia justificaciones.</p>
            @endif
        </fieldset>
    </form>
</section>
@endsection
