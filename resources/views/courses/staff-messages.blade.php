@extends(auth()->user()->activeRole() === \App\Enums\RoleName::Tutor ? 'layouts.advisor' : 'layouts.app')
@section('title', 'Coordinación entre tutor y docente')
@section('content')
<x-back-link :href="route('courses.contacts', $offering)">Volver a contactos</x-back-link>
<h1>Coordinación escolar</h1>
<p>{{ $enrollment->student->full_name }} · {{ $offering->course->name }}</p>
<p>Tutor {{ $tutor->name }} · Docente {{ $teacher->name }}</p>
<section class="portal-panel">
    <form method="post" action="{{ route('courses.staff-messages', [$offering, $enrollment]) }}">
        @csrf
        <label for="body" class="form-label">Mensaje</label>
        <textarea id="body" class="form-control mb-3" name="body" required maxlength="3000" rows="3">{{ old('body') }}</textarea>
        <button class="btn btn-primary">Enviar</button>
    </form>
</section>
@forelse($messages as $message)
    <article class="portal-panel">
        <strong>{{ $message->sender?->name }} · {{ $message->created_at->format('d/m/Y H:i') }}</strong>
        <p class="content-copy">{{ $message->body }}</p>
        @if($message->sender_id === auth()->id())<small>{{ $message->read_at ? 'Leído' : 'Pendiente de lectura' }}</small>@endif
    </article>
@empty
    <p>Aún no hay mensajes.</p>
@endforelse
{{ $messages->links() }}
@endsection
