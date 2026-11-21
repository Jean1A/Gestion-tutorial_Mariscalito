@extends($layout)
@section('title', 'Detalle de acompañamiento')
@section('content')
<x-back-link :href="route('support.index')">Volver a intervenciones</x-back-link>
<div class="advisor-page-heading">
    <div>
        <h1>{{ $restrictedDetail ? \App\Services\StudentSupportService::KINDS[$record->kind].' #'.$record->id : $record->title }}</h1>
        <p>{{ $record->student->full_name }} · {{ $record->occurred_on->format('d/m/Y') }}</p>
    </div>
    @if($editable)
        <a class="btn btn-orange" href="{{ route('support.edit', $record) }}">Actualizar intervención</a>
    @endif
</div>
<section class="portal-panel">
    <p>{{ \App\Services\StudentSupportService::STATUSES[$record->status] }}</p>
    @if(auth()->user()->activeRole() === \App\Enums\RoleName::Guardian)
        <h2 class="h5">Resumen y acuerdos publicados</h2>
        <p class="content-copy">{{ $record->family_summary }}</p>
    @else
        @if($record->dimension)
            <p>Dimensión {{ \App\Services\StudentSupportService::DIMENSIONS[$record->dimension] }}</p>
        @endif
        <p>Gravedad {{ ['low'=>'Baja','medium'=>'Media','high'=>'Alta'][$record->severity] }}</p>
        @if($record->due_on)<p>Próximo seguimiento {{ $record->due_on->format('d/m/Y') }}</p>@endif
        @unless($restrictedDetail)
            <h2 class="h5">Motivo y descripción</h2>
            @if($record->location)<p>{{ $record->location }}</p>@endif
            <p class="content-copy">{{ $record->description }}</p>
            <h2 class="h5">Acciones y acuerdos internos</h2>
            <p class="content-copy">{{ $record->agreements ?: 'Sin acuerdos registrados.' }}</p>
            @if($record->kind === 'case')<p>Seguimiento a cargo de Coordinación de tutoría.</p>@endif
            @if($record->closure_note)
                <h2 class="h5">Resultado</h2>
                <p class="content-copy">{{ $record->closure_note }}</p>
            @endif
            @if($record->family_summary)
                <h2 class="h5">Resumen para la familia</h2>
                <p class="content-copy">{{ $record->family_summary }}</p>
            @endif
        @endunless
    @endif
</section>
@if(!$restrictedDetail || $canFollow)
    <section class="portal-panel">
        <h2 class="h5">Historial de seguimiento</h2>
        @unless($restrictedDetail)
            @forelse($record->followUps as $follow)
                <article class="forum-post">
                    <strong>{{ $follow->author?->name }} · {{ $follow->created_at->format('d/m/Y H:i') }}</strong>
                    <p class="content-copy">{{ $follow->note }}</p>
                    @if($follow->next_on)<small>Próxima fecha {{ $follow->next_on->format('d/m/Y') }}</small>@endif
                </article>
            @empty
                <p>Aún no hay seguimientos.</p>
            @endforelse
        @endunless
        @if($canFollow && $record->status !== 'closed')
            <form method="post" action="{{ route('support.follow-up', $record) }}">
                @csrf
                <label for="note" class="form-label">Nueva atención o avance</label>
                <textarea class="form-control mb-3" id="note" name="note" required minlength="5" maxlength="5000" rows="3"></textarea>
                @if($editable)
                    <label for="next_on" class="form-label">Próxima fecha</label>
                    <input class="form-control mb-3" type="date" id="next_on" name="next_on">
                @endif
                <button class="btn btn-orange">Registrar seguimiento</button>
            </form>
        @endif
    </section>
@endif
@endsection
