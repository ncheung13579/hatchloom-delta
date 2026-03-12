<?php

declare(strict_types=1);

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

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

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
