<?php

declare(strict_types=1);

namespace App\Shared\Errors;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns exceptions into the two public error shapes: the JSON envelope for API and SDK clients, and the error page
 * for the portal. Nothing outside local debug mode exposes stack traces, SQL or another tenant's data.
 */
final class ExceptionRenderer
{
    /** Statuses the portal shows as the error page. */
    private const PAGE_STATUSES = [403, 404, 419, 429, 500, 503];

    public static function register(Exceptions $exceptions): void
    {
        $renderer = new self;

        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => self::wantsEnvelope($request),
        );

        $exceptions->render(static fn (Throwable $e, Request $request): ?Response => $renderer->render($e, $request));
    }

    public static function wantsEnvelope(Request $request): bool
    {
        return $request->is('api/*', 'sdk/*')
            || ($request->expectsJson() && ! $request->hasHeader('X-Inertia'));
    }

    public function render(Throwable $e, Request $request): ?Response
    {
        $requestId = $request->attributes->get(AssignRequestId::ATTRIBUTE);
        $requestId = is_string($requestId) ? $requestId : null;
        $error = $this->describe($e);

        if (self::wantsEnvelope($request)) {
            return response()->json(
                (new ErrorEnvelope($error['code'], $error['message'], $requestId, $error['details']))->toArray(),
                $error['status'],
                $error['headers'],
            );
        }

        // The portal keeps Laravel's own handling of form validation and of redirects to sign-in.
        if ($e instanceof ValidationException || $e instanceof AuthenticationException) {
            return null;
        }

        if (! in_array($error['status'], self::PAGE_STATUSES, true)) {
            return null;
        }

        // Local debugging keeps the framework's detailed page for unexpected errors.
        if ($error['status'] === 500 && config('app.debug') === true) {
            return null;
        }

        [$title, $sentence] = $this->copy($error['status']);

        $response = Inertia::render('errors/Error', [
            'status' => $error['status'],
            'title' => $title,
            'message' => $sentence,
            'requestId' => $requestId,
        ])->toResponse($request)->setStatusCode($error['status']);
        $response->headers->add($error['headers']);

        return $response;
    }

    /**
     * @return array{status: int, code: string, message: string, details: array<string, mixed>, headers: array<string, string>}
     */
    private function describe(Throwable $e): array
    {
        if ($e instanceof ApplicationException) {
            return [
                'status' => $e->httpStatus(),
                'code' => $e->errorCode(),
                'message' => $e->publicMessage(),
                'details' => $e->details(),
                'headers' => $e->headers(),
            ];
        }

        if ($e instanceof ValidationException) {
            return $this->error(422, 'VALIDATION_FAILED', 'The given data was invalid.', ['fields' => $this->fields($e)]);
        }

        if ($e instanceof AuthenticationException) {
            return $this->error(401, 'UNAUTHENTICATED', 'Authentication is required.');
        }

        if ($e instanceof AuthorizationException) {
            return $this->error(403, 'FORBIDDEN', 'You do not have permission to do this.');
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return $this->error(404, 'NOT_FOUND', 'The requested resource was not found.');
        }

        if ($e instanceof TokenMismatchException) {
            return $this->error(419, 'SESSION_EXPIRED', 'Your session expired. Reload the page and try again.');
        }

        if ($e instanceof ThrottleRequestsException) {
            return $this->error(429, 'RATE_LIMITED', 'Too many requests. Try again shortly.', headers: $this->headersOf($e));
        }

        if ($e instanceof InvalidSignatureException) {
            return $this->error(403, 'FORBIDDEN', 'This link is invalid or has expired.');
        }

        if ($e instanceof HttpExceptionInterface) {
            return $this->fromHttpStatus($e);
        }

        return $this->error(500, 'INTERNAL_ERROR', 'Something went wrong.');
    }

    /**
     * @return array{status: int, code: string, message: string, details: array<string, mixed>, headers: array<string, string>}
     */
    private function fromHttpStatus(HttpExceptionInterface $e): array
    {
        $status = $e->getStatusCode();

        // Laravel converts several exceptions (authorization, token mismatch, model not found) into plain HTTP
        // exceptions before they reach the renderer, so the same codes are mapped from their statuses here.
        [$code, $message] = match (true) {
            $status === 403 => ['FORBIDDEN', 'You do not have permission to do this.'],
            $status === 404 => ['NOT_FOUND', 'The requested resource was not found.'],
            $status === 419 => ['SESSION_EXPIRED', 'Your session expired. Reload the page and try again.'],
            $status === 429 => ['RATE_LIMITED', 'Too many requests. Try again shortly.'],
            $status === 400 => ['BAD_REQUEST', 'The request could not be understood.'],
            $status === 405 => ['METHOD_NOT_ALLOWED', 'This method is not allowed here.'],
            $status === 413 => ['PAYLOAD_TOO_LARGE', 'The request is too large.'],
            $status === 415 => ['UNSUPPORTED_MEDIA_TYPE', 'This content type is not supported.'],
            $status === 503 => ['SERVICE_UNAVAILABLE', 'BitFlag is temporarily unavailable. Try again shortly.'],
            $status < 500 => ['BAD_REQUEST', 'The request could not be completed.'],
            default => ['INTERNAL_ERROR', 'Something went wrong.'],
        };

        return $this->error($status, $code, $message, headers: $this->headersOf($e));
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     * @return array{status: int, code: string, message: string, details: array<string, mixed>, headers: array<string, string>}
     */
    private function error(int $status, string $code, string $message, array $details = [], array $headers = []): array
    {
        return ['status' => $status, 'code' => $code, 'message' => $message, 'details' => $details, 'headers' => $headers];
    }

    /**
     * @return list<array{path: string, code: string, message: string}>
     */
    private function fields(ValidationException $e): array
    {
        $validator = $e->validator;
        $failed = $validator instanceof Validator ? $validator->failed() : [];
        $fields = [];

        foreach ($e->errors() as $path => $messages) {
            if (! is_array($messages)) {
                continue;
            }

            $failedRules = $failed[$path] ?? [];
            $rules = is_array($failedRules) ? array_keys($failedRules) : [];

            foreach (array_values($messages) as $index => $message) {
                $rule = $rules[$index] ?? null;

                $fields[] = [
                    'path' => (string) $path,
                    'code' => is_string($rule) ? strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $rule)) : 'invalid',
                    'message' => is_string($message) ? $message : '',
                ];
            }
        }

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    private function headersOf(HttpExceptionInterface $e): array
    {
        $headers = [];

        foreach ($e->getHeaders() as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value))) {
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function copy(int $status): array
    {
        return match ($status) {
            403 => ['You don’t have access', 'Ask a project Owner if you need access to this.'],
            404 => ['Page not found', 'This page doesn’t exist, or you don’t have access to it.'],
            419 => ['Your session expired', 'Reload the page and try again.'],
            429 => ['Too many requests', 'Wait a moment and try again.'],
            503 => ['BitFlag is unavailable', 'We’re temporarily unable to serve this page. Try again shortly.'],
            default => ['Something went wrong', 'The error has been recorded. Include the request ID if you contact support.'],
        };
    }
}
