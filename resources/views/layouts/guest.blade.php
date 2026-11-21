<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f1f5f9">
    <title>@yield('title', 'MARISCALITO · Acceso')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="welcome-body">
    @auth
        <form class="welcome-logout" method="post" action="{{ route('logout') }}">
            @csrf
            <button class="portal-action" type="submit"><x-icon name="box-arrow-right"/> Salir</button>
        </form>
    @endauth
    <main class="welcome-main">
        <div class="auth-panel @yield('panel-class')">
            <x-brand subtitle="Tutoría y orientación educativa"/>
            @section('guest-feedback')
                <x-feedback/>
            @show
            @yield('content')
            <div class="auth-school">{{ config('school.name') }}</div>
        </div>
        <a class="welcome-privacy" href="{{ route('privacy.policy') }}">Política de privacidad</a>
    </main>
</body>
</html>
