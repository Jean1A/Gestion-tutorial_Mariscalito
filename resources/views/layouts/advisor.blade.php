@php
    $advisor = auth()->user();
    $portalLinks = [
        ['Inicio', 'house-door', route('dashboard'), request()->routeIs('dashboard')],
        ['Mis secciones', 'people', route('advisor.sections'), request()->routeIs('advisor.sections', 'sections.show', 'attendance.*')],
        ['Estudiantes', 'person', route('advisor.students'), request()->routeIs('advisor.students', 'advisor.student', 'students.show')],
        ['Sala de cómputo', 'book', route('student-tutoring.staff.index'), request()->routeIs('student-tutoring.staff.*'), app(\App\Services\StudentTutoringService::class)->pendingRequests($advisor)],
    ];

    if ($advisor->permits('courses.record')) {
        $portalLinks[] = ['Asistencia y cursos', 'book', route('courses.mine'), request()->routeIs('courses.mine', 'courses.record', 'courses.contacts', 'courses.staff-messages')];
    }
    if ($advisor->permits('attendance.record')) {
        $pending = app(\App\Services\AdvisorPortalService::class)->justifications($advisor)->where('status', 'pending')->count();
        $portalLinks[] = ['Justificaciones', 'file-earmark-text', route('justifications.index'), request()->routeIs('justifications.index'), $pending];
        $portalLinks[] = ['Acompañamiento', 'file-earmark-text', route('support.index'), request()->routeIs('support.index', 'support.show', 'support.edit', 'support.create', 'support.profile')];
        $portalLinks[] = ['Tutoría grupal', 'people', route('support.sessions'), request()->routeIs('support.sessions*')];
    }
    if ($advisor->permits('communications.send')) {
        $portalLinks[] = ['Citaciones', 'calendar-week', route('communications.index'), request()->routeIs('communications.*')];
        $portalLinks[] = ['Mensajes', 'chat-dots', route('support.messages'), request()->routeIs('support.messages')];
    }
    if ($advisor->permits('attendance.view')) {
        $portalLinks[] = ['Alertas', 'bell', route('advisor.alerts'), request()->routeIs('advisor.alerts')];
    }
@endphp
@include('layouts.portal', ['portalTitle' => 'Portal del asesor'])
