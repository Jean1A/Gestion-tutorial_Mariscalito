@php
    $portalStudent = $child ?? app(\App\Services\GuardianPortalService::class)->selected(auth()->user());
    $childrenUrl = route('portal.children');
    $portalLinks = [
        ['Inicio', 'house-door', route('portal.home'), request()->routeIs('portal.home')],
        ['Cursos', 'book', $portalStudent ? route('portal.courses', $portalStudent) : $childrenUrl, request()->routeIs('portal.courses', 'portal.course', 'portal.absences', 'portal.justify')],
    ];
    if ($portalStudent) {
        $portalLinks[] = ['Asistencia diaria', 'calendar-week', route('portal.daily', $portalStudent), request()->routeIs('portal.daily')];
        $portalLinks[] = ['Guías de tutoría', 'file-earmark-text', route('portal.materials', $portalStudent), request()->routeIs('portal.materials*')];
    }
    $portalLinks[] = ['Boletas', 'file-earmark-text', $portalStudent ? route('portal.reports', $portalStudent) : $childrenUrl, request()->routeIs('portal.reports', 'portal.report*')];
    $portalLinks[] = ['Escuela de padres', 'people', route('portal.family'), request()->routeIs('portal.family', 'portal.workshop')];
    $portalLinks[] = ['Acompañamiento', 'file-earmark-text', route('support.index'), request()->routeIs('support.index', 'support.show')];
    $portalLinks[] = ['Mensajes al tutor', 'chat-dots', route('support.messages'), request()->routeIs('support.messages')];
@endphp
@include('layouts.portal', ['portalTitle' => 'Portal de padres'])
