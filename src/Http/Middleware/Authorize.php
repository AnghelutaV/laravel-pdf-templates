<?php

namespace AnghelutaV\PdfTemplates\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Admin UI access, like laravel/telescope and log-viewer:
 * - the gate from config('pdf-templates.gate') decides when it is defined
 *   (Gate::define('viewPdfTemplates', fn ($user = null) => ...)),
 * - without a gate the UI is only open in the "local" environment.
 * Set the config value to null to rely on the route middleware alone.
 */
class Authorize
{
    public function handle(Request $request, Closure $next)
    {
        $gate = config('pdf-templates.gate', 'viewPdfTemplates');

        $allowed = match (true) {
            $gate === null => true,
            Gate::has($gate) => Gate::forUser($request->user())->allows($gate),
            default => app()->environment('local'),
        };

        abort_unless($allowed, 403);

        return $next($request);
    }
}
