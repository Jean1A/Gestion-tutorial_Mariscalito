@php
    $staff = auth()->user();
    $staffRole = $staff->activeRole();
    $staffTitle = match ($staffRole) {
        \App\Enums\RoleName::Teacher => 'Portal del docente',
        \App\Enums\RoleName::Assistant => 'Portal del auxiliar',
        default => $staffRole->label(),
    };
    $portalLinks = [];
    if ($staffRole !== \App\Enums\RoleName::Teacher) {
        $portalLinks[] = ['Inicio', 'house-door', route('dashboard'), request()->routeIs('dashboard')];
    }
    if ($staffRole === \App\Enums\RoleName::Teacher && $staff->permits('courses.record')) {
        $portalLinks[] = ['Mis cursos', 'book', route('courses.mine'), request()->routeIs('courses.*')];
        $portalLinks[] = ['Foros de tutoría', 'chat-dots', route('student-tutoring.staff.index'), request()->routeIs('student-tutoring.staff.*')];
        $portalLinks[] = ['Incidencias reportadas', 'file-earmark-text', route('support.index'), request()->routeIs('support.*')];
    }
    if ($staffRole === \App\Enums\RoleName::Assistant) {
        if ($staff->permits('students.view')) {
            $portalLinks[] = ['Secciones y asistencia', 'people', route('dashboard').'#authorized-sections', request()->routeIs('attendance.*', 'sections.*', 'students.show')];
        }
        if ($staff->permits('courses.record')) {
            $portalLinks[] = ['Asistencia por curso', 'book', route('courses.mine'), request()->routeIs('courses.*')];
        }
        if (app(\App\Services\StudentSupportService::class)->canReport($staff)) {
            $portalLinks[] = ['Incidencias y seguimiento', 'file-earmark-text', route('support.index'), request()->routeIs('support.*')];
        }
    }
@endphp
@include('layouts.portal', ['portalTitle' => $staffTitle, 'showSchoolMenu' => in_array($staffRole, [\App\Enums\RoleName::Administrator, \App\Enums\RoleName::Coordinator], true)])
