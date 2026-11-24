<?php

declare(strict_types=1);

namespace App\Modules\Environments\Domain;

/**
 * What a protection edit amounts to. Required approvals only mean something while the environment is protected, so turning
 * protection off clears them. A change reason is required whenever the environment is protected.
 */
final readonly class PolicyChange
{
    /** Approvals a policy can require at launch. */
    public const MAX_APPROVALS = 1;

    public function __construct(
        public bool $isProtected,
        public int $requiredApprovals,
    ) {}

    public static function from(bool $isProtected, int $requiredApprovals): self
    {
        $approvals = $isProtected ? max(0, min($requiredApprovals, self::MAX_APPROVALS)) : 0;

        return new self($isProtected, $approvals);
    }

    public function requireReason(): bool
    {
        return $this->isProtected;
    }

    public function differsFrom(bool $isProtected, int $requiredApprovals): bool
    {
        return $this->isProtected !== $isProtected || $this->requiredApprovals !== $requiredApprovals;
    }

    /** Whether the edit asks for more approvals than the current policy, which a paid plan includes. */
    public function raisesApprovalsAbove(int $current): bool
    {
        return $this->requiredApprovals > 0 && $this->requiredApprovals > $current;
    }
}
