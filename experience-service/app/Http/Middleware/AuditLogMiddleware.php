<?php

/**
 * AuditLogMiddleware — Structured audit trail for mutating API requests.
 *
 * Architecture role:
 *   Implements the Decorator pattern (SDD Section 6.6) by wrapping the normal
 *   request-response pipeline with audit logging. This middleware does NOT alter
 *   the request or response — it only observes and records.
 *
 * Request lifecycle position:
 *   HTTP Request -> MockAuthMiddleware -> AuditLogMiddleware -> Controller -> Response
 *                                                                              |
 *                                                        (log entry written here, after response)
 *
 *   The audit entry is recorded AFTER the response is generated (post-middleware).
 *   This is intentional: we need the HTTP status code, which is only known after
 *   the controller has executed. The call to $next($request) runs the entire
 *   downstream pipeline first, then we log on the way back out.
 *
 * What gets logged:
 *   Only mutating methods (POST, PUT, PATCH, DELETE). Read-only methods (GET, HEAD,
 *   OPTIONS) are skipped to avoid flooding the log with high-frequency read traffic.
 *
 * Security:
 *   Request body fields like 'password', 'token', 'secret' are redacted before
 *   logging to prevent credential leakage in log files or log aggregation services.
 *
 * @see \App\Http\Middleware\MockAuthMiddleware  Must run before this so user_id is available
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
     *
     * @var list<string>
     */
    private const AUDITABLE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Run the request through the pipeline, then log if it was a mutating method.
     *
     * Note: $next($request) is called FIRST — this means the entire controller
     * pipeline executes before we reach the logging code. This is a "post-middleware"
     * pattern, as opposed to MockAuthMiddleware which is a "pre-middleware" (it runs
     * logic before calling $next).
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Let the request proceed through the rest of the pipeline (controller, etc.)
        $response = $next($request);

        // Only log mutating operations — reads are too frequent to audit at this level.
        if (in_array($request->method(), self::AUDITABLE_METHODS, true)) {
            $this->recordAuditEntry($request, $response);
        }

        return $response;
    }

    /**
     * Write a structured audit log entry for a mutating request.
     */
    private function recordAuditEntry(Request $request, Response $response): void
    {
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
