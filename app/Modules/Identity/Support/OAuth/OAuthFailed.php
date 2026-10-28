<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support\OAuth;

use RuntimeException;

/**
 * The provider did not complete the exchange: a refused code, an unreachable provider, an answer without a user id.
 * The message is safe to show.
 */
final class OAuthFailed extends RuntimeException {}
