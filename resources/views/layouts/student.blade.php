@php
    $portalLinks = [
        ['Sala de cómputo', 'house-door', route('student-tutoring.home'), request()->routeIs('student-tutoring.home', 'student-tutoring.activity')],
        ['Bitácora personal', 'book', route('student-tutoring.entries', 'journal'), request()->route('kind') === 'journal'],
        ['Buzón de orientación', 'chat-dots', route('student-tutoring.entries', 'request'), request()->route('kind') === 'request'],
        ['Metas de estudio', 'mortarboard', route('student-tutoring.entries', 'goal'), request()->route('kind') === 'goal' || request()->routeIs('student-tutoring.goal')],
    ];
@endphp
@include('layouts.portal', ['portalTitle' => 'Portal del estudiante'])
