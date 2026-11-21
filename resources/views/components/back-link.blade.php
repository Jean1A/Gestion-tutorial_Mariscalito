@props(['href' => null])

@php
    $href ??= route(match (auth()->user()?->activeRole()) {
        \App\Enums\RoleName::Guardian => 'portal.home',
        null => 'login',
        default => 'dashboard',
    });
@endphp

<a href="{{ $href }}" {{ $attributes->class(['back-link']) }}>
    <x-icon name="arrow-left"/>
    {{ $slot->isEmpty() ? 'Volver al inicio' : $slot }}
</a>
