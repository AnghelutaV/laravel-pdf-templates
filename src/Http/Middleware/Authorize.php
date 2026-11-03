<?php

namespace AnghelutaV\PdfTemplates\Http\Middleware;

use AnghelutaV\PdfTemplates\PdfTemplates;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Admin UI access, like laravel/telescope and log-viewer, first rule that applies:
 * - PdfTemplates::auth(fn ($request) => ...) when it is set,
 * - the gate from config('pdf-templates.gate') when it is defined
 *   (Gate::define('viewPdfTemplates', fn ($user = null) => ...)),
 * - without either, the UI is only open in the "local" environment.
 * Set the config gate to null (and no auth()) to rely on the route middleware alone.
 */
class Authorize
{
    public function handle(Request $request, Closure $next)
    {
        $gate = config('pdf-templates.gate', 'viewPdfTemplates');

        $allowed = match (true) {
            ($callback = PdfTemplates::check($request)) !== null => $callback,
            $gate === null => true,
            Gate::has($gate) => Gate::forUser($request->user())->allows($gate),
            default => app()->environment('local'),
        };

        abort_unless($allowed, 403);

        return $next($request);
    }
}
