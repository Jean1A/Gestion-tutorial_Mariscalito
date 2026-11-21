@extends('layouts.guest')
@section('panel-class', 'auth-panel--wide')
@section('title','Política de privacidad')
@section('content')
<x-back-link/>
@include('privacy.policy-content')
@if(auth()->check() && auth()->user()->activeRole()===\App\Enums\RoleName::Guardian)<a class="btn btn-outline-primary mt-3" href="{{ route('privacy.consent') }}">Administrar consentimiento</a>@endif
@endsection
