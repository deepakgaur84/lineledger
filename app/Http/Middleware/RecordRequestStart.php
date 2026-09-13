<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps when a web request began — before route-model binding loads any
 * record — as the `request_started_ms` request attribute.
 *
 * An edit form compares it with the time a record was last saved by someone
 * else: a save that landed after this moment may not be reflected in the data
 * the page loaded, so the form reloads before editing (GuardsEditLockedForm).
 * Uses Carbon's clock so tests can travel.
 */
class RecordRequestStart
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('request_started_ms', (int) now()->getTimestampMs());

        return $next($request);
    }
}
