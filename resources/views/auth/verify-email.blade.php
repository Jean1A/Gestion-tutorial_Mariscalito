@extends('layouts.guest')
@section('title', 'Verificar correo')
@section('content')
<x-back-link/>
<div class="account-form">
    <h1 class="h3">Verifica tu correo</h1>
    <p>Enviaremos un enlace a {{ auth()->user()->email }} para confirmar que el correo te pertenece.</p>
    <form method="post" action="{{ route('verification.send') }}">@csrf<button class="btn btn-orange">Enviar enlace</button></form>
</div>
@endsection
