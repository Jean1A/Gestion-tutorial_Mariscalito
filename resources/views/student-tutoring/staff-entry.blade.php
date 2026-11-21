@extends('layouts.app')
@section('title', 'Registro confidencial')
@section('content')
<a class="back-link" href="{{ route('student-tutoring.staff.index') }}"><x-icon name="arrow-left"/> Volver a la sala</a>
<h1>{{ config('student_tutoring.entry_labels.'.$entry->kind) }}</h1>
<p class="portal-subtitle">{{ $student->full_name }} · Registro confidencial</p>
<section class="portal-panel">
    <h2 class="h5">{{ $entry->category ? config('student_tutoring.request_categories.'.$entry->category) : 'Reflexión personal' }}</h2>
    <p class="content-copy">{{ \Illuminate\Support\Facades\Crypt::decryptString($entry->content) }}</p>
    @if($entry->feedback)
        <h2 class="h5">Respuesta actual</h2>
        <p class="content-copy">{{ \Illuminate\Support\Facades\Crypt::decryptString($entry->feedback) }}</p>
    @endif
    @if($entry->referred_at)<p class="soft-note">Derivada el {{ \Illuminate\Support\Carbon::parse($entry->referred_at)->format('d/m/Y H:i') }}.</p>@endif
</section>
<section class="portal-panel">
    <h2 class="h5">Responder al estudiante</h2>
    <form method="post" action="{{ route('student-tutoring.staff.feedback', $entry->id) }}" autocomplete="off">
        @csrf
        <input type="hidden" name="action" value="reply">
        <label for="private_feedback" class="form-label">{{ $entry->feedback ? 'Reemplazar respuesta' : 'Respuesta confidencial' }}</label>
        <textarea name="private_feedback" id="private_feedback" class="form-control mb-3" rows="4" maxlength="2400" required></textarea>
        <button class="btn btn-orange">Guardar respuesta</button>
    </form>
</section>
@if($canRefer)
    <section class="portal-panel">
        <h2 class="h5">Derivar a Coordinación de tutoría</h2>
        <p>Coordinación podrá leer esta solicitud y responder al estudiante.</p>
        <form method="post" action="{{ route('student-tutoring.staff.feedback', $entry->id) }}" data-confirm="¿Derivar esta solicitud confidencial a Coordinación de tutoría?">
            @csrf
            <input type="hidden" name="action" value="refer">
            <button class="btn btn-outline-primary">Derivar solicitud</button>
        </form>
    </section>
@endif
@endsection
