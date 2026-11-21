@extends('layouts.guest')
@section('title', 'Configuración pendiente')
@section('content')
<div>
    <h1 class="h4">Configuración pendiente</h1>
    <p>El colegio debe definir los parámetros iniciales de seguridad antes de habilitar el acceso.</p>
    <a href="{{ route('login') }}" class="btn btn-outline-primary">Volver</a>
</div>
@endsection
