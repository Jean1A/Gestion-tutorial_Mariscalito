@props(['subtitle'=>'Portal de padres','compact'=>false])
<a href="{{ auth()->check() ? route('dashboard'):route('login') }}" class="school-brand {{ $compact ? 'school-brand--compact':'' }}" aria-label="Mariscalito, inicio">
    <img src="{{ asset('brand/mariscalito.svg') }}" width="88" height="74" alt="">
    <span><strong>MARISCALITO</strong>@if($subtitle)<small>{{ $subtitle }}</small>@endif</span>
</a>
