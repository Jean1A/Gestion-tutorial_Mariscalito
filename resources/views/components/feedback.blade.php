@props(['errorTitle' => 'Revisa los datos ingresados'])

@if(session('status'))
    <x-notice tone="success">
        <p>{{ session('status') }}</p>
    </x-notice>
@endif

@if($errors->any())
    <x-notice tone="error" :title="$errorTitle">
        @if($errors->count() === 1)
            <p>{{ $errors->first() }}</p>
        @else
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
    </x-notice>
@endif
