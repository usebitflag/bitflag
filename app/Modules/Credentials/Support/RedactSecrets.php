<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Support;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Masks anything shaped like a credential in every log record: the message, the context and the extra data. A secret that
 * reaches a log line, an exception message or a stack trace argument is hidden before it is written.
 */
final class RedactSecrets implements ProcessorInterface
{
    private const PATTERN = '/bf_(?:(?:srv|pub|mgt)_[a-z0-9]{1,4}|pat)_[A-Za-z0-9]{36}/';

    public const MASK = '[credential hidden]';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->mask($record->message),
            context: $this->maskAll($record->context),
            extra: $this->maskAll($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function maskAll(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = match (true) {
                is_string($value) => $this->mask($value),
                is_array($value) => $this->maskAll($value),
                $value instanceof \Throwable => $this->mask($value::class.': '.$value->getMessage()."\n".$value->getTraceAsString()),
                $value instanceof \Stringable => $this->mask((string) $value),
                default => $value,
            };
        }

        return $values;
    }

    private function mask(string $text): string
    {
        return preg_replace(self::PATTERN, self::MASK, $text) ?? $text;
    }
}
