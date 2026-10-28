<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\RequestAccountEmailData;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use InvalidArgumentException;

/**
 * Asks for an account or security email in the same transaction as the change that caused it. The message names the
 * template and the person and carries no address, link or token; `SendAccountEmail` builds those when it sends.
 */
final class RequestAccountEmail
{
    public const TYPE = 'internal.email.requested';

    private const MAX_DETAIL_LENGTH = 120;

    public function __construct(private readonly Outbox $outbox) {}

    public function handle(RequestAccountEmailData $data): void
    {
        foreach ($data->details as $key => $value) {
            if (is_string($value) && mb_strlen($value) > self::MAX_DETAIL_LENGTH) {
                throw new InvalidArgumentException("Email detail \"{$key}\" is too long.");
            }
        }

        $this->outbox->push(OutboxMessage::forAccount($data->userId, self::TYPE, 'user', $data->userId, [
            'template' => $data->template->value,
            'user_id' => $data->userId,
            'details' => $data->details,
        ]));
    }
}
