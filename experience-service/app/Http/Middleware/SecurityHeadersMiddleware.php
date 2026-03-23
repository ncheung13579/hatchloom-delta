<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds security response headers to every API response.
 *
 * Content-Security-Policy blocks inline script execution, preventing XSS
 * even if a frontend rendering bug were to inject stored data as HTML.
 * Combined with JSON encoding (which escapes < > & ") and frontend
 * framework auto-escaping, this provides defense-in-depth with zero
 * risk of data loss — unlike input sanitization approaches.
 */
class SecurityHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
