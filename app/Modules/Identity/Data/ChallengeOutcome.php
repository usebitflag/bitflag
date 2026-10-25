<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

/**
 * How a second-factor attempt ended.
 */
enum ChallengeOutcome
{
    case Authenticated;
    case WrongCode;
    case Locked;
    case Unavailable;
}
