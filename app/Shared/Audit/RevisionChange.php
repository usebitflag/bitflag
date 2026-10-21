<?php

declare(strict_types=1);

namespace App\Shared\Audit;

/**
 * The environment revision before and after a publication, rollback or emergency disable.
 */
final readonly class RevisionChange
{
    public function __construct(public ?int $from, public ?int $to) {}
}
