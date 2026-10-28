<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Data\RecordOnboardingProgressData;
use App\Modules\Organizations\Domain\OnboardingStep;
use App\Modules\Organizations\Models\Organization;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Errors\AuthorizationDenied;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

/**
 * Notes that a step of the sign-up wizard is done: choosing a plan, inviting the team (or skipping it), or seeing the ready
 * screen. Only someone who manages the organization may. It is progress through setup, not a change to customer data, so
 * it is not audited.
 */
final class RecordOnboardingProgress
{
    private const KEYS = [
        OnboardingStep::Plan->value => 'plan_chosen_at',
        OnboardingStep::Team->value => 'team_step_done_at',
        OnboardingStep::Ready->value => 'ready_seen_at',
    ];

    public function __construct(private readonly ClockInterface $clock) {}

    /**
     * @throws AuthorizationDenied
     */
    public function handle(Actor $actor, RecordOnboardingProgressData $data): void
    {
        $key = self::KEYS[$data->completed->value] ?? null;

        if ($key === null || ! $actor->can(Capability::OrgManage, new OrganizationScope($data->organizationId))) {
            throw new AuthorizationDenied;
        }

        DB::transaction(function () use ($data, $key): void {
            $organization = Organization::query()->whereKey($data->organizationId)->lockForUpdate()->firstOrFail();
            $progress = $organization->onboarding;

            if (isset($progress[$key])) {
                return;
            }

            $progress[$key] = $this->clock->now()->format(DATE_ATOM);
            $organization->forceFill(['onboarding' => $progress])->save();
        });
    }
}
