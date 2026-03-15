<?php

declare(strict_types=1);

/**
 * AuditLogMiddleware — Structured audit logging for mutating HTTP requests.
 *
 * Implements the Decorator pattern (SDD Section 6.6) by wrapping the request
 * pipeline with audit logging behavior. This middleware is transparent to the
 * controllers — they do not know they are being audited.
 *
 * How it fits into the request lifecycle:
 *  1. The request arrives and passes through MockAuthMiddleware (authentication)
 *  2. This middleware lets the request proceed to the controller first
 *  3. After the controller generates its response, this middleware checks if the
 *     HTTP method is mutating (POST, PUT, PATCH, DELETE)
 *  4. If mutating, it writes a structured log entry with user context, request
 *     details, and the HTTP status code from the response
 *  5. The response is returned unchanged to the client
 *
 * The log entry is written AFTER response generation so we can capture the
 * status code. Read-only requests (GET, HEAD, OPTIONS) are passed through
 * without any logging overhead.
 *
 * Security: Sensitive fields (passwords, tokens, API keys) are automatically
 * redacted from the logged request body to prevent credential leakage.
 *
 * @see \App\Http\Middleware\MockAuthMiddleware  Runs before this middleware
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Audit trail middleware (Decorator pattern — SDD Section 6.6).
 *
 * Decorates all POST, PUT, PATCH, and DELETE requests with structured audit
 * logging. The log entry is written AFTER the response is generated so we can
 * capture the HTTP status code. GET/HEAD/OPTIONS requests are passed through
 * without logging.
 *
 * Sensitive fields (passwords, tokens, secrets) are redacted from the recorded
 * request body to prevent credential leakage in log files.
 */
class AuditLogMiddleware
{
    /**
     * Request body fields that must never appear in audit logs.
     *
     * If any of these keys exist in the request payload, their values are
     * replaced with '***REDACTED***' before being written to the log.
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
     * GET/HEAD/OPTIONS requests are read-only and are not logged to keep
     * the audit trail focused on data-changing operations.
     *
     * @var list<string>
     */
    private const AUDITABLE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        // Let the request proceed through the pipeline first. We need the
        // response object to log the HTTP status code.
        $response = $next($request);

        // Only log mutating requests — read-only requests pass through silently.
        if (in_array($request->method(), self::AUDITABLE_METHODS, true)) {
            $this->recordAuditEntry($request, $response);
        }

        return $response;
    }

    /**
     * Write a structured audit log entry for a mutating request.
     *
     * The log entry includes: timestamp, user identity (from MockAuthMiddleware),
     * HTTP method, URI, sanitized request body, and the response status code.
     * This provides a complete audit trail of who did what and when.
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
     * Iterates over the SENSITIVE_FIELDS list and replaces any matching keys
     * with a redaction marker. This is a shallow check — nested objects are
     * not recursively sanitized (sufficient for the current API surface).
     *
     * @param  array<string, mixed> $body The raw request body
     * @return array<string, mixed>       The sanitized body safe for logging
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
