@extends('layouts.app')
@section('title', $definition['label'])
@section('content')
<x-back-link/>
@php($isYearPage = $resource === 'years')
<div class="advisor-page-heading">
    <div>
        <h1>{{ $isYearPage ? 'Año escolar' : 'Estructura académica' }}</h1>
        <p class="portal-subtitle">{{ $isYearPage ? 'Prepara, activa, cierra o reabre un año escolar.' : 'Organiza los niveles, grados, secciones y períodos del colegio.' }}</p>
    </div>
    <a class="btn btn-orange" href="{{ route('academics.create', $resource) }}">{{ $isYearPage ? 'Crear año escolar' : 'Añadir registro' }}</a>
</div>
@if($isYearPage)
    <p class="soft-note"><x-icon name="info-circle"/> Al cerrar un año, sus datos se ocultan de los portales y reportes. Al reabrirlo vuelven a estar disponibles y el año queda en preparación.</p>
@else
    <section class="portal-panel">
        <nav class="nav nav-pills flex-wrap gap-2" aria-label="Estructura académica">
            @foreach(\Illuminate\Support\Arr::except(config('school-resources'), ['years']) as $key => $tab)
                <a class="nav-link {{ $key === $resource ? 'active' : '' }}" href="{{ route('academics.index', $key) }}" @if($key === $resource) aria-current="page" @endif>{{ $tab['label'] }}</a>
            @endforeach
        </nav>
        <details class="mt-3">
            <summary class="portal-action">Otras tareas del colegio</summary>
            <div class="course-shortcuts mt-2">
                <a href="{{ route('calendar.index') }}"><x-icon name="calendar-week"/> Calendario</a>
                @can('create', \App\Models\Student::class)
                    <a href="{{ route('students.index') }}"><x-icon name="people"/> Estudiantes y matrículas</a>
                    <a href="{{ route('imports.index') }}"><x-icon name="upload"/> Importar estudiantes</a>
                @endcan
                @can('manage-assignments')<a href="{{ route('assignments.index') }}"><x-icon name="person"/> Vínculos y auxiliares</a>@endcan
                @can('coordinate-tutors')
                    <a href="{{ route('coordination.assignments') }}"><x-icon name="people"/> Asignar tutores</a>
                    <a href="{{ route('coordination.movements') }}"><x-icon name="arrow-repeat"/> Promociones y traslados</a>
                @endcan
                @can('manage-courses')<a href="{{ route('courses.manage') }}"><x-icon name="book"/> Cursos y docentes</a>@endcan
            </div>
        </details>
    </section>
@endif

<div class="card table-responsive">
    <table class="table mb-0">
        <thead>
            <tr>
                @foreach($definition['fields'] as $label)
                    <th scope="col">{{ $label }}</th>
                @endforeach
                <th scope="col">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($definition['fields'] as $field => $label)
                        <td>
                            @if($field === 'is_active')
                                @if($isYearPage)
                                    {{ $row->closed_at ? 'Cerrado' : ($row->is_active ? 'Activo' : 'Preparación') }}
                                @else
                                    {{ $row->is_active ? 'Sí' : 'No' }}
                                @endif
                            @else
                                {{ $row->$field instanceof \DateTimeInterface ? $row->$field->format('d/m/Y') : $row->$field }}
                            @endif
                        </td>
                    @endforeach
                    <td>
                        <div class="d-flex flex-column align-items-start gap-2">
                            @unless($isYearPage && $row->closed_at)
                                <a class="portal-action" href="{{ route('academics.edit', [$resource, $row->id]) }}">Editar</a>
                            @endunless
                            @if($isYearPage && ($row->is_active || $row->closed_at))
                                <details>
                                    <summary @class(['portal-action', 'portal-action--danger' => $row->is_active])>
                                        {{ $row->is_active ? 'Cerrar año' : 'Reabrir para preparación' }}
                                    </summary>
                                    <form class="year-status-form" method="post" action="{{ route('academics.years.status', $row) }}">
                                        @csrf
                                        <input type="hidden" name="action" value="{{ $row->is_active ? 'close' : 'reopen' }}">
                                        <label for="year-reason-{{ $row->id }}" class="form-label">Motivo</label>
                                        <input class="form-control" id="year-reason-{{ $row->id }}" name="reason" required minlength="8" maxlength="500">
                                        <button type="submit" @class(['portal-action mt-2', 'portal-action--danger' => $row->is_active])>Confirmar</button>
                                    </form>
                                </details>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ count($definition['fields']) + 1 }}">Aún no hay registros.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
