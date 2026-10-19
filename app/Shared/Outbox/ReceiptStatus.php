<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

enum ReceiptStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Exhausted = 'exhausted';
}
