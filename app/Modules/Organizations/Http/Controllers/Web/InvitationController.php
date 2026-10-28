<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers\Web;

use App\Modules\Identity\Queries\CurrentPerson;
use App\Modules\Organizations\Actions\AcceptInvitation;
use App\Modules\Organizations\Data\AcceptInvitationData;
use App\Modules\Organizations\Data\InvitationSummary;
use App\Modules\Organizations\Domain\Exceptions\DomainNotAllowed;
use App\Modules\Organizations\Domain\Exceptions\InvitationUnavailable;
use App\Modules\Organizations\Domain\Exceptions\InvitationWrongAccount;
use App\Modules\Organizations\Http\Requests\AcceptInvitationRequest;
use App\Modules\Organizations\Queries\InvitationDetails;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page an invitation link opens, and accepting from it. What the page shows depends on the invitation and on who is
 * looking: someone with no account makes one, the right person signed in confirms, anyone else signed in is told, and a link
 * that cannot be used says why without naming any other invitation.
 */
final class InvitationController
{
    public function show(Request $request, InvitationDetails $details, CurrentPerson $current, string $token): Response
    {
        $summary = $details->forToken($token);

        if ($summary->state !== 'open') {
            return $this->unavailable($summary);
        }

        $person = $current->find();

        if ($person !== null && mb_strtolower($person->email) !== mb_strtolower((string) $summary->email)) {
            return Inertia::render('auth/InvitationWrongAccount', [
                'token' => $token,
                'invitedEmail' => $summary->maskedEmail(),
                'signedInAs' => $person->email,
                'organizationName' => $summary->organizationName,
            ]);
        }

        return Inertia::render('auth/AcceptInvitation', [
            'token' => $token,
            'mode' => $person === null ? 'create' : 'confirm',
            'email' => $summary->email,
            'organizationName' => $summary->organizationName,
            'inviterName' => $summary->inviterName,
            'orgRole' => $summary->orgRole?->value,
            'projects' => $summary->projects,
            'expiresAt' => $summary->expiresAt,
        ]);
    }

    public function accept(AcceptInvitationRequest $request, AcceptInvitation $accept, CurrentPerson $current, StatefulGuard $guard, string $token): RedirectResponse
    {
        $person = $current->find();

        try {
            $result = $accept->handle(new AcceptInvitationData(
                $token,
                $person?->id,
                $request->string('name')->toString(),
                $request->string('password')->toString(),
            ));
        } catch (InvitationUnavailable|InvitationWrongAccount|DomainNotAllowed) {
            // The page shows what happened, from the same facts.
            return redirect()->route('invitations.show', ['token' => $token]);
        }

        if ($result->createdAccount) {
            $guard->loginUsingId($result->userId);
            $request->session()->regenerate();
        }

        return redirect()->route('organization.home', ['organization' => $result->organizationSlug])
            ->with('toast', ['message' => 'You joined the organization.', 'tone' => 'neutral']);
    }

    /**
     * "Sign out and continue": ends the session and returns to the same invitation.
     */
    public function switchAccount(Request $request, StatefulGuard $guard, string $token): RedirectResponse
    {
        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('invitations.show', ['token' => $token]);
    }

    private function unavailable(InvitationSummary $summary): Response
    {
        return Inertia::render('auth/InvitationUnavailable', [
            'reason' => $summary->state,
            'organizationName' => $summary->state === 'expired' || $summary->state === 'revoked' ? $summary->organizationName : null,
            'inviterName' => $summary->state === 'expired' || $summary->state === 'revoked' ? $summary->inviterName : null,
        ]);
    }
}
