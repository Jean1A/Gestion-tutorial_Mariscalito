@extends('layouts.app')
@section('title', $instrument['title'])
@section('content')
<a class="back-link" href="{{ route('student-tutoring.staff.index') }}"><x-icon name="arrow-left"/> Volver a la sala</a>
<h1>{{ $instrument['title'] }}</h1>
<p class="portal-subtitle">{{ $activity->topic }} · {{ $total }} envíos</p>
@if($activity->instrument === 'forum')
    <section class="portal-panel">
        <h2 class="h5">Caso para la sección</h2>
        <p class="content-copy">{{ $activity->prompt }}</p>
        <a class="btn btn-orange" href="{{ route('student-tutoring.staff.activity', ['activity' => $activity->id, 'project' => 1]) }}" target="_blank" rel="noopener">Proyectar el caso</a>
    </section>
@elseif($activity->instrument !== 'checkin')
    <p class="soft-note">Aquí puedes consultar el total de envíos. Los resultados de investigación se consultan en Reportes de coordinación.</p>
@endif
@if(in_array($activity->instrument, ['forum', 'checkin'], true))
    <section class="portal-panel">
        <h2 class="h5">{{ $activity->instrument === 'forum' ? 'Moderar reflexiones' : 'Respuestas confidenciales de bienestar' }}</h2>
        @forelse($details as $detail)
            <article class="forum-post">
                <strong>{{ $detail->last_name }}, {{ $detail->first_name }}</strong>
                @php($payload = $tutoring->readPayload($detail))
                @if($activity->instrument === 'forum')
                    <span class="status-pill">{{ ['approved' => 'Publicada', 'hidden' => 'Oculta', 'pending' => 'Pendiente'][$detail->moderation] }}</span>
                    <p>{{ $payload['text'] }}</p>
                    <form method="post" action="{{ route('student-tutoring.staff.moderate', [$activity->id, $detail->id]) }}">
                        @csrf @method('patch')
                        <button name="moderation" value="approved" class="btn btn-sm btn-outline-primary">Publicar sin nombre</button>
                        <button name="moderation" value="hidden" class="btn btn-sm btn-outline-secondary">Ocultar</button>
                    </form>
                @else
                    <ul class="mt-2 mb-0">@foreach($instrument['items'] as $index => $question)<li>{{ $question }} · {{ $payload['answers'][$index] }}/5</li>@endforeach</ul>
                @endif
            </article>
        @empty
            <p>No hay respuestas para revisar.</p>
        @endforelse
        {{ $details->links() }}
    </section>
@endif
@endsection
