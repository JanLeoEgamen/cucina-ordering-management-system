{{-- Any other 4xx (418, 422, ...): Laravel falls back to this file when there is no NNN.blade.php. --}}
<x-error-page :code="$exception->getStatusCode()" />
