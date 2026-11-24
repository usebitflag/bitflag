<?php

declare(strict_types=1);

namespace App\Modules\Projects\Domain;

enum ExportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';
}
