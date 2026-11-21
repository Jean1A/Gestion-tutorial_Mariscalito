@props(['tone' => 'info', 'title' => null])

<div {{ $attributes->class(['portal-notice', 'portal-notice--'.$tone]) }}
    @if($tone === 'error') role="alert" @elseif($tone === 'success') role="status" @endif>
    @if($tone === 'error' || $tone === 'warning')
        <span class="portal-notice__symbol portal-notice__symbol--filled" aria-hidden="true">!</span>
    @else
        <x-icon :name="$tone === 'success' ? 'check-circle' : 'info-circle'" class="portal-notice__symbol"/>
    @endif
    <div class="portal-notice__body">
        @if($title)
            <strong class="portal-notice__title">{{ $title }}</strong>
        @endif
        {{ $slot }}
    </div>
</div>
