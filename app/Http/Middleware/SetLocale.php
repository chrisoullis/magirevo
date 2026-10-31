<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale');

        abort_unless(in_array($locale, config('translatable.locales'), true), 404);

        $application = app();
        $previousLocale = $application->getLocale();
        $application->setLocale($locale);

        try {
            return $next($request);
        } finally {
            $application->setLocale($previousLocale);
        }
    }
}
