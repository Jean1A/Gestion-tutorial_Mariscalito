@extends('layouts.student')
@section('title', 'Avance de mi meta')
@section('content')
<a class="back-link" href="{{ route('student-tutoring.entries', 'goal') }}"><x-icon name="arrow-left"/> Volver a mis metas</a>
<h1>Avance de mi meta</h1>
<p class="portal-subtitle content-copy">{{ \Illuminate\Support\Facades\Crypt::decryptString($goal->content) }}</p>
<section class="portal-panel">
    <h2 class="h5">Registrar un avance</h2>
    <form method="post" action="{{ route('student-tutoring.progress', $goal->id) }}" autocomplete="off">
        @csrf
        <label class="form-label" for="progress">Avance estimado (0 a 100 %)</label>
        <input id="progress" name="progress" class="form-control mb-3" type="number" min="0" max="100" value="{{ $updates->first()?->progress ?? 0 }}" required>
        <label class="form-label" for="private_note">¿Qué avancé o qué necesito ajustar?</label>
        <textarea id="private_note" name="private_note" class="form-control mb-3" rows="3" maxlength="1200" required></textarea>
        <button class="btn btn-orange">Guardar avance</button>
    </form>
</section>
<section class="portal-panel">
    <h2 class="h5">Historial de avances</h2>
    @forelse($updates as $update)
        <article class="forum-post"><strong>{{ $update->progress }} %</strong> · <small>{{ \Illuminate\Support\Carbon::parse($update->created_at)->format('d/m/Y H:i') }}</small><p>{{ \Illuminate\Support\Facades\Crypt::decryptString($update->note) }}</p></article>
    @empty
        <p>Aún no has registrado avances.</p>
    @endforelse
    {{ $updates->links() }}
</section>
@endsection
