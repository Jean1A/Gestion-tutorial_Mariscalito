@extends('layouts.student')
@php($label = config('student_tutoring.entry_labels.'.$kind))
@section('title', $label)
@section('content')
<a class="back-link" href="{{ route('student-tutoring.home') }}"><x-icon name="arrow-left"/> Volver a la sala</a>
<h1>{{ $label }}</h1>
<p class="portal-subtitle">{{ match($kind) { 'journal' => 'Escribe metas personales y reflexiones. Solo tú y tu tutor asignado pueden leerlas.', 'request' => 'Pide ayuda en privado. Tu tutor puede responderte o derivar la solicitud a Coordinación de tutoría.', default => 'Define lo que quieres lograr y registra tus avances durante el período escolar.' } }}</p>
<section class="portal-panel">
    <h2 class="h5">{{ match($kind) { 'journal' => 'Nueva entrada', 'request' => 'Nueva solicitud', default => 'Nueva meta' } }}</h2>
    @if($kind === 'goal' && !$periods->contains('is_active', true))
        <p class="soft-note">El colegio aún no habilitó un período para crear metas.</p>
    @else
        <form method="post" action="{{ route('student-tutoring.entries.store', $kind) }}" autocomplete="off">
            @csrf
            @if($kind === 'request')
                <p class="soft-note">Si necesitas ayuda inmediata, avisa a un adulto del colegio.</p>
                <label class="form-label" for="category">Motivo de la solicitud</label>
                <select id="category" name="category" class="form-select mb-3" required>
                    <option value="">Elige un motivo</option>
                    @foreach(config('student_tutoring.request_categories') as $value => $category)<option value="{{ $value }}">{{ $category }}</option>@endforeach
                </select>
            @elseif($kind === 'goal')
                <label class="form-label" for="academic_period_id">Período</label>
                <select id="academic_period_id" name="academic_period_id" class="form-select mb-3" required>
                    <option value="">Elige un período</option>
                    @foreach($periods->where('is_active', true) as $period)<option value="{{ $period->id }}">{{ $period->name }}</option>@endforeach
                </select>
            @endif
            <label class="form-label" for="private_content">{{ match($kind) { 'journal' => 'Mi reflexión', 'request' => '¿En qué podemos ayudarte?', default => '¿Qué quiero lograr?' } }}</label>
            <textarea name="private_content" id="private_content" class="form-control mb-3" rows="4" maxlength="2400" required></textarea>
            <button class="btn btn-orange">{{ $kind === 'request' ? 'Enviar en privado' : 'Guardar' }}</button>
        </form>
    @endif
</section>
<section class="portal-panel">
    <h2 class="h5">Mi historial</h2>
    @forelse($entries as $entry)
        <article class="forum-post">
            <small>{{ \Illuminate\Support\Carbon::parse($entry->created_at)->format('d/m/Y H:i') }}</small>
            @if($kind === 'request')
                <span class="status-pill">{{ $entry->attended_at ? 'Atendida' : ($entry->referred_at ? 'Derivada' : 'Pendiente') }}</span>
                <small>{{ config('student_tutoring.request_categories.'.$entry->category) }}</small>
            @elseif($kind === 'goal')
                <small>{{ $periods->firstWhere('id', $entry->academic_period_id)?->name }}</small>
            @endif
            <p>{{ \Illuminate\Support\Facades\Crypt::decryptString($entry->content) }}</p>
            @if($entry->feedback)
                <p><strong>Respuesta confidencial</strong></p>
                <p>{{ \Illuminate\Support\Facades\Crypt::decryptString($entry->feedback) }}</p>
            @endif
            @if($kind === 'goal')<a class="btn btn-outline-primary btn-sm mt-2" href="{{ route('student-tutoring.goal', $entry->id) }}">Ver y registrar avances</a>@endif
        </article>
    @empty
        <p>Aún no tienes registros en este espacio.</p>
    @endforelse
    {{ $entries->links() }}
</section>
@endsection
