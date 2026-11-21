@extends('layouts.student')
@section('title', $instrument['title'])
@section('content')
<a class="back-link" href="{{ route('student-tutoring.home') }}"><x-icon name="arrow-left"/> Volver a la sala</a>
<h1>{{ $instrument['title'] }}</h1>
<p class="portal-subtitle">{{ $instrument['dimension'] }} · {{ config('student_tutoring.phases.'.$activity->phase) }}</p>
<section class="portal-panel">
    @if($activity->instrument === 'forum')
        <h2>El caso de hoy</h2>
        <p class="content-copy">{{ $activity->prompt }}</p>
    @elseif($activity->instrument === 'checkin')
        <p>Elige la opción que mejor describa cómo te sientes hoy. Tu tutor puede revisar tus respuestas en privado.</p>
    @elseif($activity->phase !== 'practice')
        <p>Participar es voluntario. El reporte del estudio usa puntuaciones sin tu nombre ni DNI.</p>
    @endif

    @if($response)
        <p class="soft-note"><x-icon name="check-circle"/> {{ $response->moderation === 'pending' ? 'Tu reflexión está pendiente de revisión.' : 'Tu participación está registrada.' }}</p>
        @if($scores)
            <h2 class="h5">Mi autoevaluación</h2>
            <p>Usa estas puntuaciones para conversar con tu tutor y decidir qué quieres practicar o explorar.</p>
            <ul>@foreach($scores as $label => $score)<li>{{ $label }} · {{ round($score, 1) }} de 5</li>@endforeach</ul>
        @elseif($activity->instrument === 'checkin')
            <h2 class="h5">Así me sentí al responder</h2>
            <ul>@foreach($instrument['items'] as $index => $item)<li>{{ $item }} · {{ $answers[$index] }} de 5</li>@endforeach</ul>
            <a class="btn btn-outline-primary" href="{{ route('student-tutoring.entries', 'request') }}">Pedir orientación</a>
        @endif
    @elseif($open)
        <form method="post" action="{{ route('student-tutoring.submit', $activity->id) }}" autocomplete="off">
            @csrf
            @if($activity->instrument === 'forum')
                <label class="form-label" for="forum_text">Mi reflexión</label>
                <textarea id="forum_text" name="forum_text" class="form-control mb-3" rows="4" maxlength="1500" required></textarea>
                <p>Habla del caso sin incluir nombres ni situaciones personales. El tutor revisará tu reflexión antes de compartirla con la sección.</p>
            @else
                @foreach($instrument['items'] as $index => $item)
                    @if($activity->instrument === 'sus' && $index === 10)<h2 class="h6 mt-4">Pregunta adicional de satisfacción</h2>@endif
                    <div class="mb-3">
                        <label for="answer-{{ $index }}" class="form-label">{{ $loop->iteration }}. {{ $item }}</label>
                        <select class="form-select" id="answer-{{ $index }}" name="student_answers[{{ $index }}]" required>
                            <option value="">Elige una respuesta</option>
                            @foreach([1 => 'Totalmente en desacuerdo', 2 => 'En desacuerdo', 3 => 'Ni de acuerdo ni en desacuerdo', 4 => 'De acuerdo', 5 => 'Totalmente de acuerdo'] as $value => $label)
                                <option value="{{ $value }}">{{ $value }} · {{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
                @if($activity->phase !== 'practice')
                    <div class="form-check mb-3"><input id="assent" name="assent" value="1" type="checkbox" class="form-check-input" required><label class="form-check-label" for="assent">He leído la información y quiero participar voluntariamente.</label></div>
                @endif
            @endif
            <button class="btn btn-orange">Enviar mi respuesta</button>
        </form>
    @else
        <p class="soft-note">El horario de participación terminó.</p>
    @endif
    @if(isset($instrument['source']))
        <p class="small mt-3 mb-0"><a class="portal-action" href="{{ $instrument['source'] }}" target="_blank" rel="noopener noreferrer">{{ $instrument['source_label'] }}</a></p>
    @endif
</section>
@if($activity->instrument === 'forum')
    <section class="portal-panel">
        <h2>Reflexiones compartidas</h2>
        @forelse($posts as $post)
            <p class="forum-post">{{ $tutoring->readPayload($post)['text'] }}</p>
        @empty
            <p>Aún no hay reflexiones publicadas.</p>
        @endforelse
        {{ $posts->links() }}
    </section>
@endif
@endsection
