@php
    $isCoordinator = auth()->user()->activeRole() === \App\Enums\RoleName::Coordinator;
    $portalLinks = $isCoordinator ? [
        ['Panel general', 'house-door', route('coordination.index'), request()->routeIs('dashboard', 'coordination.index')],
        ['Reportes', 'download', route('school-reports.index'), request()->routeIs('school-reports.*')],
        ['Seguimientos y alertas', 'bell', route('coordination.follow-up'), request()->routeIs('coordination.follow-up')],
        ['Intervenciones', 'file-earmark-text', route('support.index'), request()->routeIs('support.index', 'support.show', 'support.edit', 'support.create', 'support.profile')],
        ['Buzón derivado', 'lock', route('student-tutoring.staff.index'), request()->routeIs('student-tutoring.staff.*'), app(\App\Services\StudentTutoringService::class)->pendingRequests(auth()->user())],
        ['Tutoría grupal', 'people', route('support.sessions'), request()->routeIs('support.sessions*')],
    ] : [
        ['Panel general', 'house-door', route('coordination.index'), request()->routeIs('dashboard', 'coordination.index')],
        ['Equipo de tutores', 'people', route('coordination.tutors'), request()->routeIs('coordination.tutors', 'coordination.tutor')],
        ['Secciones y asignaciones', 'mortarboard', route('coordination.assignments'), request()->routeIs('coordination.assignments')],
        ['Actividad y citaciones', 'activity', route('coordination.activities'), request()->routeIs('coordination.activities')],
        ['Seguimiento y alertas', 'bell', route('coordination.follow-up'), request()->routeIs('coordination.follow-up')],
        ['Intervenciones', 'file-earmark-text', route('support.index'), request()->routeIs('support.index', 'support.show', 'support.edit', 'support.create', 'support.profile')],
        ['Tutoría grupal', 'people', route('support.sessions'), request()->routeIs('support.sessions*')],
        ['Reportes del colegio', 'download', route('school-reports.index'), request()->routeIs('school-reports.*')],
    ];
    if ($isCoordinator && auth()->user()->can('manage-family')) {
        $portalLinks[] = ['Escuela de padres', 'people', route('family.manage.index'), request()->routeIs('family.manage.*')];
    }
@endphp
@include('layouts.portal', ['portalTitle' => $isCoordinator ? 'Coordinación de tutoría' : 'Portal del administrador', 'showSchoolMenu' => true, 'schoolMenuAfterHome' => $isCoordinator])
