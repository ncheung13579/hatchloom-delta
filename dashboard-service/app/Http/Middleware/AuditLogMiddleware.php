<?php

/**
 * AuditLogMiddleware — Structured audit logging for mutating HTTP requests.
 *
 * Design pattern: Decorator (SDD Section 6.6)
 *   This middleware "decorates" the request pipeline by wrapping the normal
 *   request->response flow with audit logging behavior. The controller and
 *   service layer are completely unaware that auditing is happening — it is
 *   layered on transparently via the middleware stack.
 *
 * Request lifecycle position:
 *   This middleware runs around the request: it calls $next($request) first
 *   to let the controller generate a response, THEN inspects the completed
 *   request+response pair to decide whether to log. This "after" positioning
 *   is deliberate — we need the HTTP status code from the response to include
 *   in the audit entry.
 *
 * What gets logged:
 *   Only mutating requests (POST, PUT, PATCH, DELETE) are audited. Read-only
 *   requests (GET, HEAD, OPTIONS) pass through without any logging overhead.
 *   Each audit entry captures: timestamp, user identity, HTTP method, URI,
 *   sanitized request body, and response status code.
 *
 * Security:
 *   The request body is sanitized before logging — fields like 'password',
 *   'token', and 'secret' are replaced with '***REDACTED***' to prevent
 *   credential leakage in log files.
 *
 * Note: The Dashboard Service is currently read-only (all GET endpoints), so
 * this middleware won't fire for any current routes. It's included to support
 * future endpoints that may accept POST/PUT/PATCH/DELETE requests.
 */

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuditLogMiddleware
{
    /**
     * Request body fields that must never appear in audit logs.
     * If any of these keys exist in the request body, their values are
     * replaced with '***REDACTED***' before the log entry is written.
     *
     * @var list<string>
     */
    private const SENSITIVE_FIELDS = [
        'password',
        'password_confirmation',
        'token',
        'secret',
        'api_key',
        'authorization',
    ];

    /**
     * HTTP methods considered mutating and therefore worth auditing.
     * GET, HEAD, and OPTIONS are excluded because they are idempotent
     * and don't change server state — logging them would create noise.
     *
     * @var list<string>
     */
    private const AUDITABLE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        // Let the request proceed through the rest of the middleware stack
        // and the controller. We capture the response so we can inspect the
        // status code for the audit log entry.
        $response = $next($request);

        // Only log mutating operations — skip read-only GET/HEAD/OPTIONS
        if (in_array($request->method(), self::AUDITABLE_METHODS, true)) {
            $this->recordAuditEntry($request, $response);
        }

        return $response;
    }

    /**
     * Write a structured audit log entry for a mutating request.
     *
     * Uses Laravel's Log facade which writes to the configured log channel
     * (typically storage/logs/laravel.log). The 'audit.mutation' channel prefix
     * makes these entries easy to filter in log aggregation tools.
     */
    private function recordAuditEntry(Request $request, Response $response): void
    {
        // $request->user() is populated by MockAuthMiddleware earlier in the stack.
        // It may be null if this middleware runs on an unauthenticated route.
        $user = $request->user();

        Log::info('audit.mutation', [
            'timestamp'       => now()->toIso8601String(),
            'user_id'         => $user?->id,
            'user_role'       => $user?->role,
            'http_method'     => $request->method(),
            'uri'             => $request->getRequestUri(),
            'request_body'    => $this->sanitizeBody($request->all()),
            'response_status' => $response->getStatusCode(),
        ]);
    }

    /**
     * Remove sensitive fields from the request body before logging.
     *
     * This is a shallow check — it only redacts top-level keys. Nested sensitive
     * fields (e.g., $body['user']['password']) are NOT caught. Currently this is
     * sufficient since our API payloads are flat, but a production version should
     * do recursive sanitization.
     *
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function sanitizeBody(array $body): array
    {
        foreach (self::SENSITIVE_FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                $body[$field] = '***REDACTED***';
            }
        }

        return $body;
    }
}
