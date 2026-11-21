@php
    $dates = $dates ?? true;
    $directory = $directory ?? false;
    $selectedYear = $years->firstWhere('id', $filters['school_year_id']);
@endphp
<form method="get" action="{{ $filterAction ?? url()->current() }}" class="coordination-filters" data-coordination-filters>
    <div><label for="school-year-filter">Año escolar</label><select class="form-select" id="school-year-filter" name="school_year_id" data-coordination-year>@foreach($years as $year)<option value="{{ $year->id }}" data-start="{{ $year->starts_on->toDateString() }}" data-end="{{ $year->ends_on->toDateString() }}" @selected($year->id === $filters['school_year_id'])>{{ $year->year }}{{ $year->closed_at ? ' · Cerrado' : ($year->is_active ? '' : ' · Preparación') }}</option>@endforeach</select></div>
    @unless(isset($profileTutor) && $profileTutor)
        <div class="coordination-filter-tutor"><label for="tutor-filter">Tutor</label><select class="form-select" id="tutor-filter" name="tutor_id"><option value="">Todos los tutores</option>@foreach($filterTutors as $filterTutor)<option value="{{ $filterTutor->id }}" @selected($filterTutor->id === $filters['tutor_id'])>{{ $filterTutor->name }}{{ $filterTutor->is_active ? '' : ' · Inactivo' }}</option>@endforeach</select></div>
    @endunless
    <div><label for="section-filter">Sección</label><select class="form-select" id="section-filter" name="section_id"><option value="">Todas las secciones</option>@foreach($filterSections as $filterSection)<option value="{{ $filterSection->id }}" @selected($filterSection->id === $filters['section_id'])>{{ $filterSection->grade->educationLevel->name }} · {{ $filterSection->grade->name }} {{ $filterSection->name }}</option>@endforeach</select></div>
    @if(request()->routeIs('dashboard', 'coordination.index'))
        <div><label for="grade-filter">Grado</label><select class="form-select" id="grade-filter" name="grade_id"><option value="">Todos los grados</option>@foreach($filterGrades as $grade)<option value="{{ $grade->id }}" @selected($grade->id === $filters['grade_id'])>{{ $grade->educationLevel->name }} · {{ $grade->name }}</option>@endforeach</select></div>
        <div><label for="period-filter">Período</label><select class="form-select" id="period-filter" name="period_id"><option value="">Todo el intervalo</option>@foreach($filterPeriods as $period)<option value="{{ $period->id }}" @selected($period->id === $filters['period_id'])>{{ $period->name }}</option>@endforeach</select></div>
        <div><label for="coordination-dimension">Dimensión tutorial</label><select class="form-select" id="coordination-dimension" name="dimension"><option value="">Todas</option>@foreach(\App\Services\StudentSupportService::DIMENSIONS as $key => $label)<option value="{{ $key }}" @selected($key === $filters['dimension'])>{{ $label }}</option>@endforeach</select></div>
    @endif
    @if($dates)
        <div><label for="from-filter">Desde</label><input class="form-control" id="from-filter" name="from" type="date" value="{{ $filters['from'] }}" min="{{ $selectedYear?->starts_on->toDateString() }}" max="{{ $selectedYear?->ends_on->toDateString() }}"></div>
        <div><label for="to-filter">Hasta</label><input class="form-control" id="to-filter" name="to" type="date" value="{{ $filters['to'] }}" min="{{ $selectedYear?->starts_on->toDateString() }}" max="{{ $selectedYear?->ends_on->toDateString() }}"></div>
    @endif
    @if($directory)
        <div class="coordination-filter-tutor"><label for="search-tutor">Buscar tutor</label><input class="form-control" id="search-tutor" name="q" value="{{ $filters['q'] }}" maxlength="100" placeholder="Nombre o DNI"></div>
        <div><label for="account-status">Cuenta</label><select class="form-select" id="account-status" name="account_status">@foreach(['active' => 'Activa', 'inactive' => 'Inactiva', 'all' => 'Todas'] as $value => $label)<option value="{{ $value }}" @selected($filters['account_status'] === $value)>{{ $label }}</option>@endforeach</select></div>
    @endif
    @if($withStatus ?? false)
        <div><label for="request-status">Solicitud</label><select class="form-select" id="request-status" name="status">@foreach(['pending' => 'Pendiente', 'approved' => 'Aprobada', 'rejected' => 'No aprobada', 'all' => 'Todas'] as $value => $label)<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach</select></div>
    @endif
    <button class="btn btn-primary" @disabled($years->isEmpty())>Aplicar</button>
    <a href="{{ $filterAction ?? url()->current() }}" class="btn btn-outline-primary">Limpiar</a>
</form>
@if($years->isEmpty())<div class="soft-note"><x-icon name="info-circle"/>Configura un año escolar para comenzar el seguimiento del equipo.</div>@endif
