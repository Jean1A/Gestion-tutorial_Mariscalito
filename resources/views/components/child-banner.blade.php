@props(['child','enrollment'=>null])
<div class="child-banner">
    <img class="student-avatar" src="{{ asset('brand/student.svg') }}" width="74" height="74" alt="">
    <div class="child-banner__identity"><strong>{{ $child->full_name }}</strong><span class="grade-badge">{{ $enrollment ? $enrollment->section->grade->name.' · Sección '.$enrollment->section->name:'Matrícula por confirmar' }}</span></div>
    <a class="btn btn-outline-primary child-change" href="{{ route('portal.children') }}"><x-icon name="people"/><span>Cambiar hijo</span></a>
</div>
