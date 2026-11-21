@php
    $schoolLinks = [];
    $simpleMenu = $portalRole === \App\Enums\RoleName::Coordinator;

    if ($portalUser->can('viewAny', \App\Models\User::class)) {
        $schoolLinks[] = [$simpleMenu ? 'Gestión de usuarios' : 'Usuarios y accesos', 'person', route('users.index'), request()->routeIs('users.*')];
    }
    if ($simpleMenu && $portalUser->can('manage-academics')) {
        $isYearPage = request()->route('resource') === 'years' || request()->routeIs('academics.years.*');
        $schoolLinks[] = ['Estructura académica', 'mortarboard', route('academics.index', 'sections'), !$isYearPage && request()->routeIs('academics.*', 'calendar.*', 'coordination.assignments*', 'coordination.movements*', 'students.*', 'imports.*', 'assignments.*', 'courses.manage')];
        $schoolLinks[] = ['Año escolar', 'calendar-week', route('academics.index', 'years'), $isYearPage];
    }
    if (!$simpleMenu && $portalUser->can('manage-academics')) {
        $schoolLinks[] = ['Estructura académica', 'mortarboard', route('academics.index', 'years'), request()->routeIs('academics.*')];
        $schoolLinks[] = ['Calendario lectivo', 'calendar-week', route('calendar.index'), request()->routeIs('calendar.*')];
        if ($portalUser->can('coordinate-tutors')) {
            $schoolLinks[] = ['Promociones y traslados', 'arrow-repeat', route('coordination.movements'), request()->routeIs('coordination.movements*')];
        }
    }
    if (!$simpleMenu && $portalUser->can('create', \App\Models\Student::class)) {
        $schoolLinks[] = ['Estudiantes y matrículas', 'people', route('students.index'), request()->routeIs('students.*')];
        $schoolLinks[] = ['Importar estudiantes', 'upload', route('imports.index'), request()->routeIs('imports.*')];
    }
    if (!$simpleMenu && $portalUser->can('manage-assignments')) {
        $schoolLinks[] = ['Vínculos y auxiliares', 'people', route('assignments.index'), request()->routeIs('assignments.*')];
    }
    if (!$simpleMenu && $portalUser->can('manage-courses')) {
        $schoolLinks[] = ['Cursos y docentes', 'book', route('courses.manage'), request()->routeIs('courses.manage')];
    }
    if (!$simpleMenu && $portalUser->can('manage-family')) {
        $schoolLinks[] = ['Escuela de padres', 'people', route('family.manage.index'), request()->routeIs('family.manage.*')];
    }
    if (!$simpleMenu && $portalUser->permits('communications.send')) {
        $schoolLinks[] = ['Comunicaciones institucionales', 'envelope', route('communications.index'), request()->routeIs('communications.*')];
    }
    if (!$simpleMenu && $portalUser->can('manage-settings')) {
        $schoolLinks[] = ['Configuración y permisos', 'lock', route('settings.index'), request()->routeIs('settings.*')];
    }
@endphp
@if($schoolLinks)
    <details class="portal-nav-group" @if(collect($schoolLinks)->contains(fn ($link) => $link[3])) open @endif>
        <summary><x-icon name="mortarboard"/><span>Gestión del colegio</span><x-icon name="chevron-right"/></summary>
        <nav class="portal-nav" aria-label="Gestión del colegio">
            @foreach($schoolLinks as [$label, $icon, $url, $active])
                <a href="{{ $url }}" class="portal-nav__link {{ $active ? 'is-active' : '' }}" @if($active) aria-current="page" @endif>
                    <x-icon :name="$icon"/><span>{{ $label }}</span>
                </a>
            @endforeach
        </nav>
    </details>
@endif
