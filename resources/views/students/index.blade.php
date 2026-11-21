@extends('layouts.app')
@section('title', 'Estudiantes')
@section('content')
<x-back-link/>
<div class="advisor-page-heading">
    <div><h1>Estudiantes</h1><p class="portal-subtitle">Consulta las matrículas y sus vínculos con apoderados.</p></div>
</div>
<section class="portal-panel">
    <div class="d-flex flex-wrap gap-2 mb-4">
        <a class="btn btn-orange" href="{{ route('students.create') }}"><x-icon name="person"/> Registrar estudiante</a>
        @can('create', \App\Models\User::class)
            @can('manage-assignments')
                <a class="btn btn-outline-primary" href="{{ route('imports.template') }}"><x-icon name="download"/> Descargar formato Excel</a>
                <a class="btn btn-outline-primary" href="{{ route('imports.index') }}"><x-icon name="upload"/> Importar estudiantes</a>
            @endcan
        @endcan
        @can('manage-assignments')
            <a class="btn btn-outline-secondary" href="{{ route('assignments.index') }}">Vínculos y asignaciones</a>
        @endcan
    </div>
    <div class="table-responsive">
        <table class="portal-table">
            <thead><tr><th scope="col">Estudiante</th><th scope="col">DNI</th><th scope="col">Última matrícula visible</th><th scope="col">Apoderados vinculados</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
            <tbody>
                @forelse($students as $student)
                    @php($enrollment = $student->enrollments->first())
                    <tr>
                        <td><strong>{{ $student->full_name }}</strong></td><td>{{ $student->dni }}</td>
                        <td>@if($enrollment?->section){{ $enrollment->section->schoolYear->year }} · {{ $enrollment->section->grade->name }} {{ $enrollment->section->name }}@else Sin matrícula visible @endif</td>
                        <td>{{ $student->verified_guardians_count }}</td>
                        <td><span class="status-pill {{ $student->is_active ? 'status-pill--success' : 'status-pill--muted' }}">{{ $student->is_active ? 'Activo' : 'Inactivo' }}</span></td>
                        <td><div class="d-flex flex-wrap gap-2"><a class="btn btn-sm btn-outline-primary" href="{{ route('students.edit', $student) }}">Editar</a><a class="btn btn-sm btn-outline-secondary" href="{{ route('students.show', $student) }}">Consultar</a></div></td>
                    </tr>
                @empty
                    <tr><td colspan="6">Aún no hay estudiantes visibles.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($students->hasPages())<div class="mt-3">{{ $students->links() }}</div>@endif
</section>
@endsection
