@extends('layouts.guardian')
@section('title', 'Guías de tutoría')
@section('content')
<x-back-link/>
<h1>Guías de tutoría compartidas</h1>
<x-child-banner :child="$child" :enrollment="$enrollment"/>
<section class="portal-panel">
    @php($hasGuides = false)
    @foreach($sessions as $session)
        @foreach($session->materials as $material)
            @continue($material['kind'] !== 'guide' || !($material['shared'] ?? false))
            @php($hasGuides = true)
            <p><a class="portal-action" href="{{ route('portal.materials.download', [$child, $session, $loop->iteration]) }}"><x-icon name="download"/> {{ $session->topic }} · {{ $session->scheduled_on->format('d/m/Y') }} · Descargar guía</a></p>
        @endforeach
    @endforeach
    @unless($hasGuides)<p>No hay guías publicadas en esta página.</p>@endunless
    {{ $sessions->links() }}
</section>
@endsection
