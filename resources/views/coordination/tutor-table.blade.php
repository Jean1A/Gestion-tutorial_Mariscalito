<div class="table-responsive">
    <table class="portal-table coordination-table">
        <caption class="visually-hidden">Actividad por tutor. Las sesiones, revisiones y envíos corresponden al intervalo seleccionado; los pendientes, al estado actual del año escolar.</caption>
        <thead><tr><th scope="col">Tutor / secciones</th><th scope="col">Sesiones iniciadas</th><th scope="col">Revisiones</th><th scope="col">Envíos</th><th scope="col">Pendientes</th><th scope="col"><span class="visually-hidden">Ver ficha</span></th></tr></thead>
        <tbody>
        @forelse($tutorRows as $rowTutor)
            <tr>
                <td><div class="coordination-person"><span class="parent-initial">{{ mb_substr($rowTutor->name, 0, 1) }}</span><div><strong>{{ $rowTutor->name }}</strong><small>@forelse($rowTutor->tutoredSections as $tutoredSection){{ $tutoredSection->grade->name }} {{ $tutoredSection->name }}{{ !$loop->last ? ' · ' : '' }}@empty Sin sección activa @endforelse</small>@unless($rowTutor->is_active)<span class="status-pill status-pill--muted">Cuenta inactiva</span>@endunless</div></div></td>
                <td>{{ $rowTutor->coordination_metrics['sessions'] }}</td><td>{{ $rowTutor->coordination_metrics['reviewed'] }}</td><td>{{ $rowTutor->coordination_metrics['communications'] }}</td>
                <td><span class="status-pill {{ $rowTutor->coordination_metrics['pending'] ? '' : 'status-pill--success' }}">{{ $rowTutor->coordination_metrics['pending'] }}</span></td>
                <td><a class="icon-button" href="{{ route('coordination.tutor', ['tutor' => $rowTutor->id, 'school_year_id' => $filters['school_year_id'], 'from' => $filters['from'], 'to' => $filters['to']]) }}" aria-label="Ver actividad de {{ $rowTutor->name }}"><x-icon name="chevron-right"/></a></td>
            </tr>
        @empty<tr><td colspan="6"><div class="advisor-calm-state"><x-icon name="people"/><strong>No se encontraron tutores</strong><p>Revisa los filtros o crea una cuenta con el rol Tutor.</p></div></td></tr>@endforelse
        </tbody>
    </table>
</div>
