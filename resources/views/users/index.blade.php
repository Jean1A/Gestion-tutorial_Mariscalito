@extends('layouts.app')
@php($directoryTitle = $roleLabels[$accountRole] ?? 'Gestión de usuarios')
@section('title', $directoryTitle)
@section('content')
@if($isCoordinator)
    <x-back-link :href="route('users.index')">Volver a Gestión de usuarios</x-back-link>
@else
    <x-back-link/>
@endif
<div class="advisor-page-heading">
    <div>
        <h1>{{ $directoryTitle }}</h1>
        <p class="portal-subtitle">Administra las cuentas y sus vínculos escolares.</p>
    </div>
</div>

<section class="portal-panel">
    @unless($isCoordinator)
        <nav class="nav nav-pills flex-wrap gap-2 mb-3" aria-label="Roles de usuario">
            <a class="nav-link {{ !$accountRole ? 'active' : '' }}" href="{{ route('users.index', ['state' => $state]) }}" @if(!$accountRole) aria-current="page" @endif>Todos</a>
            @foreach($roles as $role)
                <a class="nav-link {{ $accountRole === $role->name->value ? 'active' : '' }}" href="{{ route('users.index', ['role' => $role->name->value, 'state' => $state]) }}" @if($accountRole === $role->name->value) aria-current="page" @endif>
                    {{ $roleLabels[$role->name->value] ?? $role->label }}
                </a>
            @endforeach
        </nav>
    @endunless

    <div class="d-flex flex-wrap gap-2 mb-4">
        @can('create', \App\Models\User::class)
            <a href="{{ route('users.create', array_filter(['role' => $accountRole])) }}" class="btn btn-orange"><x-icon name="person"/> {{ match ($accountRole) { 'tutor' => 'Crear tutor', 'guardian' => 'Crear apoderado', default => 'Crear usuario' } }}</a>
        @endcan
        @if($canImport)
            <a href="{{ route('users.import.template', $accountRole) }}" class="btn btn-outline-primary"><x-icon name="download"/> Descargar formato Excel</a>
            <a href="{{ route('users.import.create', $accountRole) }}" class="btn btn-outline-primary"><x-icon name="upload"/> Importar {{ strtolower($directoryTitle) }}</a>
        @endif
    </div>
    @if($accountRole === 'guardian')
        <p class="soft-note">Las cuentas y vínculos del padrón se crean desde <a href="{{ route('students.index') }}">Estudiantes</a>. Usa esta lista para revisar sus estudiantes vinculados.</p>
    @endif

    <form class="portal-filters" method="get" action="{{ route('users.index') }}" @if($accountRole === 'tutor') data-coordination-filters @endif>
        @if($accountRole)<input type="hidden" name="role" value="{{ $accountRole }}">@endif
        @if($accountRole === 'tutor')
            <div>
                <label for="school_year_id">Año escolar</label>
                <select id="school_year_id" name="school_year_id" class="form-select" data-coordination-year>
                    <option value="">Todos los disponibles</option>
                    @foreach($years as $year)
                        <option value="{{ $year->id }}" @selected($year->id === (int) $yearId)>{{ $year->year }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="section_id">Grado y sección</label>
                <select id="section_id" name="section_id" class="form-select">
                    <option value="">Todas las secciones</option>
                    @foreach($sections as $section)
                        <option value="{{ $section->id }}" @selected($section->id === (int) $sectionId)>{{ $section->schoolYear->year }} · {{ $section->grade->educationLevel->name }} · {{ $section->grade->name }} {{ $section->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div><label for="q">Buscar por nombre o DNI</label><input class="form-control" id="q" name="q" value="{{ $search }}" maxlength="150"></div>
        <div><label for="state">Estado</label><select id="state" name="state" class="form-select"><option value="active" @selected($state === 'active')>Activos</option><option value="retired" @selected($state === 'retired')>Retirados</option></select></div>
        <button class="btn btn-outline-primary">Buscar</button>
        <a class="btn btn-outline-primary" href="{{ route('users.index', array_filter(['role' => $accountRole])) }}">Limpiar</a>
    </form>

    <div class="table-responsive">
        <table class="portal-table">
            <thead><tr><th scope="col">Nombre</th><th scope="col">DNI</th><th scope="col">Estado</th><th scope="col">{{ $accountRole === 'tutor' ? 'Grado y sección' : ($accountRole === 'guardian' ? 'Estudiantes vinculados' : 'Roles') }}</th><th scope="col">Acciones</th></tr></thead>
            <tbody>
                @forelse($users as $subject)
                    <tr>
                        <td><strong>{{ $subject->name }}</strong></td><td>{{ $subject->dni }}</td>
                        <td><span class="status-pill {{ $subject->is_active ? 'status-pill--success' : 'status-pill--muted' }}">{{ $subject->is_active ? 'Activa' : 'Retirada' }}</span></td>
                        <td>
                            @if($accountRole === 'tutor')
                                @forelse($subject->tutoredSections as $section)
                                    <div>{{ $section->grade->educationLevel->name }} · {{ $section->grade->name }} {{ $section->name }} · {{ $section->schoolYear->year }}</div>
                                @empty
                                    <span class="text-secondary">Sin sección asignada</span>
                                @endforelse
                            @elseif($accountRole === 'guardian')
                                {{ $subject->linked_students_count }}
                            @else
                                {{ $subject->roles->pluck('label')->join(', ') }}
                            @endif
                        </td>
                        <td>
                            @can('update', $subject)
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="{{ route('users.edit', array_filter(['user' => $subject, 'role' => $accountRole])) }}" class="btn btn-sm btn-outline-primary">Editar</a>
                                    @if($subject->id !== auth()->id())
                                        <form method="post" action="{{ route('users.status', array_filter(['user' => $subject, 'role' => $accountRole])) }}" data-confirm="{{ $subject->is_active ? '¿Retirar esta cuenta? Se cerrarán sus sesiones y se conservará su historial.' : '¿Reactivar esta cuenta?' }}">
                                            @csrf
                                            @method('patch')
                                            <input type="hidden" name="is_active" value="{{ $subject->is_active ? 0 : 1 }}">
                                            <button class="btn btn-sm btn-outline-secondary">{{ $subject->is_active ? 'Retirar' : 'Reactivar' }}</button>
                                        </form>
                                    @endif
                                </div>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">No se encontraron cuentas para estos filtros.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($users->hasPages())<div class="mt-3">{{ $users->links() }}</div>@endif
</section>
@endsection
