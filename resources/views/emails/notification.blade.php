<x-mail::message>
# {{ $heading }}

@if ($text)
{{ $text }}
@endif

@if ($link)
<x-mail::button :url="$link">
{{ __('Open') }}
</x-mail::button>
@endif

{{ \App\Models\Setting::appName() }}
</x-mail::message>
