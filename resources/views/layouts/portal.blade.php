@php
    $portalUser = auth()->user();
    $portalRole = $portalUser->activeRole();
    $availableRoles = $portalUser->roles;
    $notificationUrl = route('notifications.index');
    $unread = app(\App\Services\AccessScope::class)->notifications($portalUser)->whereNull('read_at')->exists();
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#eef8ff">
    <title>@yield('title', $portalTitle) · MARISCALITO</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="portal-body">
    <a class="visually-hidden-focusable skip-link" href="#portal-content">Ir al contenido</a>
    <div class="portal-shell">
        <header class="portal-mobile-header">
            <button class="icon-button" type="button" data-bs-toggle="offcanvas" data-bs-target="#portalSidebar" aria-controls="portalSidebar" aria-label="Abrir menú">
                <x-icon name="list"/>
            </button>
            <x-brand subtitle="" compact/>
            <a class="icon-button notification-bell" href="{{ $notificationUrl }}" aria-label="{{ $unread ? 'Notificaciones sin leer' : 'Notificaciones' }}">
                <x-icon name="bell"/>
                @if($unread)<span class="notification-dot" aria-hidden="true"></span>@endif
            </a>
        </header>
        <aside class="offcanvas-lg offcanvas-start portal-sidebar" tabindex="-1" id="portalSidebar" aria-labelledby="portalMenuLabel">
            <div class="offcanvas-header">
                <h2 id="portalMenuLabel" class="h5 mb-0">{{ $portalTitle }}</h2>
                <button class="btn-close" type="button" data-bs-dismiss="offcanvas" data-bs-target="#portalSidebar" aria-label="Cerrar menú"></button>
            </div>
            <div class="offcanvas-body">
                <div class="sidebar-brand"><x-brand :subtitle="$portalTitle"/></div>
                <nav class="portal-nav" aria-label="Menú principal">
                    @foreach($portalLinks as $link)
                        @php([$label, $icon, $url, $active] = $link)
                        <a href="{{ $url }}" class="portal-nav__link {{ $active ? 'is-active' : '' }}" @if($active) aria-current="page" @endif>
                            <x-icon :name="$icon"/>
                            <span>{{ $label }}</span>
                            @if(!empty($link[4]))
                                <span class="portal-nav-count" aria-label="{{ $link[4] }} pendientes">{{ $link[4] }}</span>
                            @endif
                        </a>
                        @if($loop->first && ($schoolMenuAfterHome ?? false))
                            @include('layouts.portal-school-menu')
                        @endif
                    @endforeach
                    <a href="{{ $notificationUrl }}" class="portal-nav__link {{ request()->routeIs('notifications.*') ? 'is-active' : '' }}" @if(request()->routeIs('notifications.*')) aria-current="page" @endif>
                        <x-icon name="bell"/>
                        <span>Notificaciones</span>
                        @if($unread)<span class="notification-dot" role="img" aria-label="Hay notificaciones sin leer"></span>@endif
                    </a>
                </nav>
                @if(($showSchoolMenu ?? false) && !($schoolMenuAfterHome ?? false))
                    @include('layouts.portal-school-menu')
                @endif
                <div class="sidebar-bottom">
                    @if($portalRole === \App\Enums\RoleName::Guardian && $portalUser->permits('communications.respond'))
                        <a class="sidebar-communications" href="{{ route('communications.index') }}" @if(request()->routeIs('communications.*')) aria-current="page" @endif>
                            <x-icon name="envelope"/> Comunicados y citaciones
                        </a>
                    @endif
                    <div class="parent-identity">
                        <span class="parent-initial" aria-hidden="true">{{ mb_substr($portalUser->name, 0, 1) }}</span>
                        <span><strong>{{ $portalUser->name }}</strong><small>{{ $portalRole->label() }}</small></span>
                    </div>
                    @if($availableRoles->count() > 1)
                        <form class="role-selector" method="post" action="{{ route('role.switch') }}">
                            @csrf
                            <label for="portal-role" class="form-label">Función activa</label>
                            <select id="portal-role" name="role" class="form-select form-select-sm">
                                @foreach($availableRoles as $availableRole)
                                    <option value="{{ $availableRole->name->value }}" @selected($portalRole === $availableRole->name)>{{ $availableRole->label }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-sm btn-outline-primary mt-2">Cambiar función</button>
                        </form>
                    @endif
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button class="portal-logout"><x-icon name="box-arrow-right"/> Cerrar sesión</button>
                    </form>
                </div>
            </div>
        </aside>
        <main class="portal-main" id="portal-content">
            <x-feedback/>
            @if(str_ends_with($portalUser->email ?? '', '@example.test'))
                <div class="soft-note soft-note--orange mb-3"><x-icon name="info-circle"/> Datos ficticios de demostración</div>
            @endif
            @if($portalUser->email && !$portalUser->hasVerifiedEmail())
                <p class="email-reminder"><x-icon name="info-circle"/> <span><a href="{{ route('verification.notice') }}">Verifica tu correo</a> para recuperar tu contraseña.</span></p>
            @endif
            @yield('content')
            <x-portal-footer/>
        </main>
    </div>
</body>
</html>
