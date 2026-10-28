<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

/**
 * What came of a batch of invitations: the ones created, and the addresses that were turned down with the reason.
 */
final readonly class InviteMembersResult
{
    /**
     * @param  list<array{email: string, invitationId: string, queued: bool}>  $created
     * @param  array<string, string>  $rejected  email => reason
     */
    public function __construct(public array $created, public array $rejected) {}
}
