{{-- HTTP 403: also shows the sentence the app itself gave to abort(403, ...), if any. --}}
<x-error-page :code="403" :detail="$exception->getMessage()" />
