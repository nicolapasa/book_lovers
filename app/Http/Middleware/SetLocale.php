<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(in_array($request->header('X-Locale'), ['it', 'en'], true) ? $request->header('X-Locale') : 'it');

        return $next($request);
    }
}
