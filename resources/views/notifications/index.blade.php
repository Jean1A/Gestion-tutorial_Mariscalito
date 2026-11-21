@extends(auth()->user()->activeRole() === \App\Enums\RoleName::Guardian ? 'layouts.guardian' : (auth()->user()->activeRole() === \App\Enums\RoleName::Tutor ? 'layouts.advisor' : 'layouts.app'))
@section('title', 'Notificaciones')
@section('content')
<x-back-link/>
<h1>Notificaciones</h1>
<p class="portal-subtitle">Consulta tus avisos del colegio.</p>

<section class="notification-feed" aria-label="Bandeja de notificaciones">
    @if($notifications->isNotEmpty())
        <ul class="notification-list" role="list">
            @foreach($notifications as $notification)
                <li @class(['notification-item', 'notification-item--unread' => !$notification->read_at])>
                    <span class="notification-avatar"><x-icon name="bell"/></span>
                    <article class="notification-bubble">
                        <div class="notification-meta">
                            <span class="notification-state">{{ $notification->read_at ? 'Leída' : 'Nueva' }}</span>
                            <time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->format('d/m/Y H:i') }}</time>
                        </div>
                        <h2>{{ $notification->data['title'] }}</h2>
                        <p>{{ $notification->data['message'] }}</p>
                        <div class="portal-action-group">
                            <a class="portal-action" href="{{ $notification->data['url'] }}">Consultar <x-icon name="arrow-right"/></a>
                            @unless($notification->read_at)
                                <form method="post" action="{{ route('notifications.read', $notification->id) }}">
                                    @csrf
                                    <button type="submit" class="portal-action"><x-icon name="check-lg"/> Marcar como leída</button>
                                </form>
                            @endunless
                        </div>
                    </article>
                </li>
            @endforeach
        </ul>
    @else
        <div class="notification-empty">
            <h2>Estás al día</h2>
            <p>Tus nuevas notificaciones aparecerán aquí.</p>
        </div>
    @endif
    {{ $notifications->links() }}
</section>

@if(auth()->user()->activeRole() === \App\Enums\RoleName::Guardian)
    <section class="portal-panel notification-preferences mt-3">
        <h2 class="h5">Avisos por WhatsApp o SMS</h2>
        <p>Son opcionales y dependen del proveedor que configure el colegio. Solo se envía un aviso genérico para consultar el portal, no el contenido reservado.</p>
        <form method="post" action="{{ route('privacy.external-notifications') }}">
            @csrf
            <input type="hidden" name="enabled" value="0">
            <label class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="enabled" value="1" @checked(auth()->user()->external_notifications_enabled)>
                Autorizo avisos externos al teléfono registrado
            </label>
            <button type="submit" class="btn btn-outline-primary">Guardar preferencia</button>
        </form>
    </section>
@endif
@endsection
