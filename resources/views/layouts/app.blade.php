@php($layoutUser = auth()->user())
@if(!$layoutUser || $layoutUser->must_change_password || !$layoutUser->activeRole())
    @include('layouts.guest')
@elseif($layoutUser->can('coordinate-tutors'))
    @include('layouts.coordination')
@elseif($layoutUser->can('advisor-portal'))
    @include('layouts.advisor')
@elseif($layoutUser->can('guardian-portal'))
    @include('layouts.guardian')
@elseif($layoutUser->can('student-tutoring'))
    @include('layouts.student')
@else
    @include('layouts.staff')
@endif
