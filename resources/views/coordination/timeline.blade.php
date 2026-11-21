<div class="coordination-timeline">
    @forelse($activities as $activity)
        <article class="coordination-timeline-item"><span class="coordination-timeline-dot" aria-hidden="true"></span><div><a href="{{ route('coordination.tutor', ['tutor' => $activity->tutor_id, 'school_year_id' => $filters['school_year_id']]) }}">{{ $activity->name }}</a><p>{{ \App\Services\TutorCoordinationService::ACTIVITY_LABELS[$activity->action] }}</p><time datetime="{{ \Carbon\Carbon::parse($activity->created_at)->toIso8601String() }}">{{ \Carbon\Carbon::parse($activity->created_at)->format('d/m/Y · H:i') }}</time></div></article>
    @empty<div class="advisor-calm-state"><x-icon name="activity"/><strong>Sin actividad registrada</strong><p>Los registros, revisiones y envíos de los tutores aparecerán aquí.</p></div>@endforelse
</div>
