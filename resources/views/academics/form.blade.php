@extends('layouts.app')
@section('title', $definition['label'])
@section('content')
<x-back-link :href="route('academics.index', $resource)">Volver al listado</x-back-link>
<h1 class="h3 mb-3">{{ $subject->exists ? 'Editar' : 'Crear' }} · {{ $definition['label'] }}</h1>
<div class="card"><div class="card-body"><form method="post" action="{{ $subject->exists ? route('academics.update', [$resource, $subject->id]) : route('academics.store', $resource) }}">@csrf @if($subject->exists) @method('put') @endif
<div class="row g-3">@foreach($definition['fields'] as $field => $label)<div class="col-md-6"><label class="form-label" for="{{ $field }}">{{ $label }}</label>
@if(isset($options[$field]))<select class="form-select" id="{{ $field }}" name="{{ $field }}" required><option value="">Selecciona</option>@foreach($options[$field] as $value => $text)<option value="{{ $value }}" @selected(old($field, $subject->$field) == $value)>{{ $text }}</option>@endforeach</select>
@elseif($field === 'is_active')<select class="form-select" id="{{ $field }}" name="{{ $field }}"><option value="1" @selected(old($field, $subject->exists ? $subject->$field : 1))>Sí</option><option value="0" @selected(!old($field, $subject->exists ? $subject->$field : 1))>No</option></select>
@else<input class="form-control" id="{{ $field }}" name="{{ $field }}" type="{{ str_ends_with($field, '_on') ? 'date' : ($field === 'year' ? 'number' : 'text') }}" value="{{ old($field, $subject->$field instanceof \DateTimeInterface ? $subject->$field->format('Y-m-d') : $subject->$field) }}" required>@endif
</div>@endforeach<div class="col-12"><button class="btn btn-primary">Guardar</button><a class="btn btn-outline-secondary" href="{{ route('academics.index', $resource) }}">Cancelar</a></div></div></form></div></div>
@endsection
