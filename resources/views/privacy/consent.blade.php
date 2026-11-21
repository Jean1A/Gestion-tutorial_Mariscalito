@extends('layouts.guest')
@section('panel-class','auth-panel--children')
@section('title','Consentimiento informado')
@section('content')
@if($current)
    <x-back-link/>
@endif
<div class="card mb-3"><div class="card-body">@include('privacy.policy-content')</div></div>
<div class="card"><div class="card-body"><h2 class="h5">Estudiantes vinculados a esta autorización</h2><ul>@forelse($children as $child)<li>{{ $child->full_name }}</li>@empty<li>El colegio aún no ha validado estudiantes para tu cuenta.</li>@endforelse</ul>
<form method="post" action="{{ route('privacy.accept') }}">@csrf<label class="form-check"><input class="form-check-input" type="checkbox" name="consent" value="1" required><span class="form-check-label">Leí la política y autorizo el tratamiento descrito para el portal escolar, respecto de mi cuenta y de los estudiantes indicados cuya representación fue validada por el colegio.</span></label><button class="btn btn-primary mt-3">Aceptar y continuar</button></form>
@if($current)<form method="post" action="{{ route('privacy.withdraw') }}" class="mt-3" data-confirm="¿Deseas retirar el consentimiento del portal?">@csrf<button class="btn btn-outline-secondary">Retirar consentimiento</button></form>@endif
<p class="small mt-3 mb-0">La casilla no está marcada previamente. Si no deseas aceptar, puedes cerrar sesión y comunicarte con el colegio.</p></div></div>
@endsection
