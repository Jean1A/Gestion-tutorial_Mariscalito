@extends('layouts.app')
@section('title','Registrar estudiante')
@section('content')
<x-back-link :href="route('students.index')">Volver a Estudiantes</x-back-link>
<h1 class="h3">{{ $subject->exists ? 'Editar estudiante' : 'Registrar estudiante' }}</h1><p>Un traslado conserva la matrícula y asistencia anteriores. El inicio de la nueva sección debe ser posterior.</p>
<div class="card"><div class="card-body"><form method="post" action="{{ $subject->exists ? route('students.update',$subject) : route('students.store') }}">@csrf @if($subject->exists) @method('put') @endif<div class="row g-3">
@foreach(['dni'=>'DNI','first_name'=>'Nombres','last_name'=>'Apellidos','birth_date'=>'Fecha de nacimiento'] as $field=>$label)<div class="col-md-6"><label for="{{ $field }}" class="form-label">{{ $label }}</label><input id="{{ $field }}" class="form-control" name="{{ $field }}" type="{{ $field==='birth_date' ? 'date':'text' }}" value="{{ old($field,$subject->$field instanceof \DateTimeInterface ? $subject->$field->format('Y-m-d') : $subject->$field) }}" @required($field!=='birth_date') @if($field==='dni') maxlength="8" inputmode="numeric" @endif></div>@endforeach
<div class="col-md-6"><label for="is_active" class="form-label">Cuenta del estudiante</label><select id="is_active" name="is_active" class="form-select"><option value="1" @selected(old('is_active',$subject->exists ? $subject->is_active : 1))>Activo</option><option value="0" @selected(!old('is_active',$subject->exists ? $subject->is_active : 1))>Inactivo</option></select></div>
<div class="col-md-6"><label for="section_id" class="form-label">Sección</label><select id="section_id" name="section_id" class="form-select" required><option value="">Selecciona</option>@foreach($sections as $section)<option value="{{ $section->id }}" @selected(old('section_id',$enrollment?->section_id)==$section->id)>{{ $section->schoolYear->year }} · {{ $section->grade->educationLevel->name }} {{ $section->grade->name }} {{ $section->name }}</option>@endforeach</select></div>
<div class="col-md-4"><label for="status" class="form-label">Estado de matrícula</label><select id="status" name="status" class="form-select">@foreach(['active'=>'Activo','withdrawn'=>'Retirado','transferred'=>'Trasladado'] as $value=>$label)<option value="{{ $value }}" @selected(old('status',$enrollment?->status ?? 'active')===$value)>{{ $label }}</option>@endforeach</select></div>
<div class="col-md-4"><label for="starts_on" class="form-label">Inicio de matrícula</label><input id="starts_on" name="starts_on" class="form-control" type="date" value="{{ old('starts_on',$enrollment?->starts_on?->format('Y-m-d')) }}" required></div>
<div class="col-md-4"><label for="ends_on" class="form-label">Fin (retiro o traslado)</label><input id="ends_on" name="ends_on" class="form-control" type="date" value="{{ old('ends_on',$enrollment?->ends_on?->format('Y-m-d')) }}"></div><div class="col-12"><button class="btn btn-primary">Guardar</button></div></div></form></div></div>
@if($subject->exists)
    @can('create', \App\Models\User::class)
        <section class="portal-panel mt-3">
            <div class="portal-panel__heading"><x-icon name="lock"/><h2>Acceso a la sala de cómputo</h2></div>
            <p>El estudiante inicia sesión con su DNI y la contraseña temporal. Debe cambiarla al ingresar.</p>
            @if($portalAccount)
                <p>Estado de acceso · {{ $portalAccount->is_active ? 'Habilitado' : 'Desactivado' }}</p>
                @if($portalAccount->dni !== $subject->dni)<p class="soft-note">El DNI de la ficha cambió. Guarda una contraseña temporal para actualizar el acceso al nuevo DNI.</p>@endif
            @endif
            <form method="post" action="{{ route('students.portal-access', $subject) }}">
                @csrf
                <input type="hidden" name="account_action" value="save">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="portal-password">Contraseña temporal</label><input id="portal-password" type="password" class="form-control" name="password" autocomplete="new-password" required minlength="12"><div class="form-text">Mínimo 12 caracteres, con mayúsculas, minúsculas y números.</div></div>
                    <div class="col-md-6"><label class="form-label" for="portal-confirmation">Confirmar contraseña</label><input id="portal-confirmation" type="password" class="form-control" name="password_confirmation" autocomplete="new-password" required></div>
                    <div class="col-12"><button class="btn btn-orange">{{ $portalAccount?->is_active ? 'Restablecer contraseña' : 'Habilitar acceso' }}</button></div>
                </div>
            </form>
            @if($portalAccount?->is_active)
                <form class="mt-2" method="post" action="{{ route('students.portal-access', $subject) }}" data-confirm="¿Desactivar el acceso a la sala y cerrar sus sesiones?">
                    @csrf
                    <button class="btn btn-outline-secondary" name="account_action" value="disable">Desactivar acceso</button>
                </form>
            @endif
        </section>
    @endcan
    @if($researchEnrollment)
        <section class="portal-panel">
            <div class="portal-panel__heading"><x-icon name="bar-chart"/><h2>Participación en la investigación</h2></div>
            <p>Registra la autorización del apoderado documentada por el colegio para el año de la matrícula vigente. El estudiante decide si responde cada encuesta.</p>
            <form method="post" action="{{ route('students.research-permission', $subject) }}" class="portal-filters">
                @csrf
                <div><label for="research_authorized">Autorización para investigación</label>
                    <select id="research_authorized" name="research_authorized" class="form-select">
                        <option value="0" @selected(!$researchAuthorized)>Sin autorización vigente</option>
                        <option value="1" @selected($researchAuthorized)>Autorización documentada</option>
                    </select>
                </div>
                <button class="btn btn-outline-primary">Guardar autorización</button>
            </form>
        </section>
    @endif
@endif
@endsection
