@extends('layouts.coordination')
@section('title', 'Secciones y asignaciones')
@section('content')
<x-back-link/>
<div class="advisor-page-heading"><div><span class="advisor-eyebrow">ORGANIZACIÓN DEL EQUIPO</span><h1 class="portal-title">Cada sección, acompañada</h1><p class="portal-subtitle">Asigna al tutor responsable y mantén actualizada la cobertura del colegio.</p></div></div>
@include('coordination.filters', ['dates' => false])
<p class="soft-note"><x-icon name="info-circle"/>Al asignar un responsable se desactivan los vínculos anteriores de esa sección. Sus registros se conservan. Desvincular retira el acceso como tutor de sección; las asignaciones docentes se administran en Cursos y docentes.</p>
<div class="coordination-assignment-grid">
    @forelse($sections as $section)
        @php($activeTutors = $section->tutors->filter(fn($assignedTutor) => $assignedTutor->pivot->is_active && $assignedTutor->hasRole(\App\Enums\RoleName::Tutor)))
        <section class="portal-panel coordination-assignment-card">
            <div class="coordination-assignment-heading"><span class="advisor-section-letter">{{ $section->name }}</span><div><h2>{{ $section->grade->name }} · {{ $section->name }}</h2><p>{{ $section->grade->educationLevel->name }} · {{ $section->schoolYear->year }}</p></div><span class="status-pill {{ $activeTutors->isNotEmpty() ? 'status-pill--success' : '' }}">{{ $activeTutors->isNotEmpty() ? 'Con tutor' : 'Sin tutor' }}</span></div>
            <p class="coordination-assignment-enrollment"><x-icon name="person"/>{{ $section->enrollments_count }} matrículas activas @unless($section->is_active && $section->schoolYear->is_active)<span class="status-pill status-pill--muted">Sección o año inactivo</span>@endunless</p>
            @forelse($section->tutors->where('pivot.is_active', true) as $assignedTutor)
                <div class="coordination-assigned-person"><span class="parent-initial">{{ mb_substr($assignedTutor->name, 0, 1) }}</span><div><a href="{{ route('coordination.tutor', ['tutor' => $assignedTutor, 'school_year_id' => $section->school_year_id]) }}">{{ $assignedTutor->name }}</a><small>Tutor responsable</small></div><form method="post" action="{{ route('coordination.assignments.save', $section) }}" data-confirm="¿Desvincular a {{ $assignedTutor->name }} de esta sección? El historial se conservará.">@csrf @method('put')<input type="hidden" name="tutor_id" value="{{ $assignedTutor->id }}"><input type="hidden" name="is_active" value="0"><button class="portal-action portal-action--danger" type="submit">Desvincular</button></form></div>
            @empty<div class="coordination-assignment-empty"><x-icon name="people"/><span>Esta sección necesita un tutor responsable.</span></div>@endforelse
            <form method="post" action="{{ route('coordination.assignments.save', $section) }}" class="coordination-assignment-form" data-confirm="Se actualizará el tutor responsable de esta sección y se conservará el historial. ¿Continuar?">
                @csrf @method('put')<input type="hidden" name="is_active" value="1">
                <label for="assign-tutor-{{ $section->id }}">{{ $activeTutors->isNotEmpty() ? 'Cambiar responsable' : 'Asignar responsable' }}</label>
                <div><select class="form-select" id="assign-tutor-{{ $section->id }}" name="tutor_id" required @disabled(!$section->is_active || !$section->schoolYear->is_active)><option value="">Selecciona un tutor</option>@foreach($filterTutors->where('is_active', true) as $availableTutor)<option value="{{ $availableTutor->id }}">{{ $availableTutor->name }}</option>@endforeach</select><button class="btn btn-orange" @disabled(!$section->is_active || !$section->schoolYear->is_active)>Guardar</button></div>
            </form>
            @if($section->tutors->where('pivot.is_active', false)->isNotEmpty())<details class="coordination-assignment-history"><summary class="portal-action">Vínculos anteriores</summary>@foreach($section->tutors->where('pivot.is_active', false) as $previousTutor)<p>{{ $previousTutor->name }} <span class="status-pill status-pill--muted">Desvinculado</span></p>@endforeach</details>@endif
        </section>
    @empty<div class="empty-state"><x-icon name="mortarboard"/><h2>No hay secciones para estos filtros</h2><p>Selecciona otro año o registra la estructura académica del colegio.</p>@can('manage-academics')<a href="{{ route('academics.index', 'sections') }}" class="btn btn-outline-primary">Gestionar secciones</a>@endcan</div>@endforelse
</div>
{{ $sections->links() }}
@endsection
