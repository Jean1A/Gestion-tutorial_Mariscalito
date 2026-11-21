@extends($isTeacher ? 'layouts.app' : 'layouts.advisor')
@section('title', 'Contactos del curso')
@section('content')
<x-back-link :href="route('courses.record', $offering)">Volver al curso</x-back-link>
<h1>Contactos · {{ $offering->course->name }}</h1>
@forelse($roster as $enrollment)
    <section class="portal-panel">
        <h2 class="h5">{{ $enrollment->student->full_name }}</h2>
        @forelse($contacts[$enrollment->id] as $guardian)
            <div class="portal-action-group mb-2">
                <span>{{ $guardian->name }}</span>
                @if($guardian->phone)<a class="portal-action" href="tel:{{ $guardian->phone }}">Llamar</a>@endif
                @if($guardian->email)<a class="portal-action" href="mailto:{{ $guardian->email }}">Correo</a>@endif
                @unless($isTeacher)
                    <a class="portal-action" href="{{ route('support.messages',['student_id'=>$enrollment->student_id,'tutor_id'=>auth()->id(),'guardian_id'=>$guardian->id]) }}">Mensaje en el portal</a>
                @endunless
            </div>
        @empty
            <p>Sin apoderados con vínculo y consentimiento vigentes.</p>
        @endforelse
        @if($canMessageStaff)
            <a class="btn btn-outline-primary" href="{{ route('courses.staff-messages',[$offering,$enrollment]) }}">{{ $isTeacher ? 'Mensaje al tutor' : 'Mensaje al docente' }}</a>
        @endif
    </section>
@empty
    <p>Sin estudiantes con matrícula vigente.</p>
@endforelse
{{ $roster->links() }}
@endsection
