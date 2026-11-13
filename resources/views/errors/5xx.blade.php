{{-- Any other 5xx: Laravel falls back to this file when there is no NNN.blade.php. --}}
<x-error-page :code="$exception->getStatusCode()" />
