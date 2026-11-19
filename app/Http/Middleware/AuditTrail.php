<?php

namespace App\Http\Middleware;

use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// The audit trail's one hook into the request cycle: it sits in the `web` group, so EVERY route
// is covered, including the ones added later, with no line to remember in a controller.
//
// handle() runs before the controller (it notes who is acting and what the record looked like),
// terminate() runs after the response exists (it writes the entry with the final status, so a
// 403 or a failed validation is logged too). Both are inside rescue(): a problem in the audit
// trail is reported to the log and never reaches the person.
class AuditTrail
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('audit.context', rescue(fn () => Audit::begin($request), null));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        rescue(fn () => Audit::finish($request, $response, $request->attributes->get('audit.context')));
    }
}
