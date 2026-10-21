<?php

declare(strict_types=1);

namespace App\Shared\Errors;

/**
 * The error body returned by every API and SDK route:
 * {"error": {"code", "message", "request_id", "details"}}.
 */
final readonly class ErrorEnvelope
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $code,
        public string $message,
        public ?string $requestId,
        public array $details = [],
    ) {}

    /**
     * @return array{error: array{code: string, message: string, request_id: string|null, details: array<string, mixed>|\stdClass}}
     */
    public function toArray(): array
    {
        return [
            'error' => [
                'code' => $this->code,
                'message' => $this->message,
                'request_id' => $this->requestId,
                // An empty details list must serialize as {} and not as [].
                'details' => $this->details === [] ? new \stdClass : $this->details,
            ],
        ];
    }
}
