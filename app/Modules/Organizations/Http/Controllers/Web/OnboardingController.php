<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers\Web;

use App\Modules\Environments\Queries\EnvironmentTemplates;
use App\Modules\Identity\Queries\CurrentPerson;
use App\Modules\Identity\Queries\EmailVerification;
use App\Modules\Organizations\Actions\ChooseOrganization;
use App\Modules\Organizations\Actions\CreateOrganization;
use App\Modules\Organizations\Actions\InviteMembers;
use App\Modules\Organizations\Actions\RecordOnboardingProgress;
use App\Modules\Organizations\Data\ChooseOrganizationData;
use App\Modules\Organizations\Data\CreateOrganizationData;
use App\Modules\Organizations\Data\InviteGrant;
use App\Modules\Organizations\Data\InviteMembersData;
use App\Modules\Organizations\Data\RecordOnboardingProgressData;
use App\Modules\Organizations\Http\Requests\CreateFirstProjectRequest;
use App\Modules\Organizations\Http\Requests\CreateOrganizationRequest;
use App\Modules\Organizations\Http\Requests\InviteTeamRequest;
use App\Modules\Organizations\Queries\OnboardingState;
use App\Modules\Organizations\Queries\OrganizationSlugs;
use App\Modules\Projects\Actions\CreateProject;
use App\Modules\Projects\Data\CreateProjectData;
use App\Modules\Projects\Queries\ProjectLookup;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The sign-up wizard after the account: organization, first project, inviting the team, and the ready screen. Where a
 * person is comes from the facts (see OnboardingStepResolver); the routes only keep them on that step.
 */
final class OnboardingController
{
    public function __construct(
        private readonly CurrentPerson $currentPerson,
        private readonly OnboardingState $state,
    ) {}

    /** GET /onboarding */
    public function resume(): RedirectResponse
    {
        $person = $this->currentPerson->find();
        abort_if($person === null, 403);

        return redirect($this->state->for($person->id)->path());
    }

    /** GET /onboarding/organization */
    public function organization(): Response
    {
        return Inertia::render('onboarding/Organization', [
            'teamSizes' => [
                ['value' => 'solo', 'label' => 'Just me'],
                ['value' => 'small', 'label' => '2–10'],
                ['value' => 'medium', 'label' => '11–50'],
                ['value' => 'large', 'label' => '50+'],
            ],
        ]);
    }

    /** POST /onboarding/organization */
    public function storeOrganization(CreateOrganizationRequest $request, Actor $actor, CreateOrganization $create, ChooseOrganization $choose): RedirectResponse
    {
        $result = $create->handle($actor, new CreateOrganizationData(
            $request->string('name')->toString(),
            $request->string('slug')->toString(),
            $request->input('team_size') === null ? null : $request->string('team_size')->toString(),
        ));

        $choose->handle($actor, new ChooseOrganizationData($result->organizationId, false));

        return $this->resume();
    }

    /** GET /onboarding/{organization}/project */
    public function project(Request $request): Response
    {
        return Inertia::render('onboarding/Project', [
            'organization' => $this->organizationProps($request),
            'environments' => array_map(
                static fn ($environment): array => ['name' => $environment->name, 'key' => $environment->key],
                app(EnvironmentTemplates::class)->default(),
            ),
        ]);
    }

    /** POST /onboarding/{organization}/project */
    public function storeProject(CreateFirstProjectRequest $request, Actor $actor, CreateProject $create): RedirectResponse
    {
        $person = $this->currentPerson->find();
        abort_if($person === null, 403);

        $status = $this->state->for($person->id);
        abort_if($status->organizationId === null, 404);

        $environments = [];

        foreach ($request->array('environments') as $environment) {
            if (is_array($environment) && is_string($environment['name'] ?? null) && is_string($environment['key'] ?? null)) {
                $environments[] = ['name' => $environment['name'], 'key' => $environment['key']];
            }
        }

        $create->handle($actor, new CreateProjectData(
            $status->organizationId,
            $request->string('name')->toString(),
            $request->string('key')->toString(),
            null,
            $environments,
        ));

        return $this->resume();
    }

    /** GET /onboarding/{organization}/team */
    public function team(Request $request, ProjectLookup $projects, EmailVerification $verification): Response
    {
        $person = $this->currentPerson->find();
        abort_if($person === null, 403);

        $status = $this->state->for($person->id);
        $project = $status->organizationId === null ? null : $projects->oldest($status->organizationId);

        return Inertia::render('onboarding/Team', [
            'organization' => $this->organizationProps($request),
            'projectName' => $project?->name,
            'emailVerified' => $verification->isVerified($person->id),
            'roles' => array_map(static fn (ProjectRole $role): array => ['value' => $role->value, 'label' => ucfirst($role->value)], ProjectRole::cases()),
        ]);
    }

    /** POST /onboarding/{organization}/team */
    public function inviteTeam(InviteTeamRequest $request, Actor $actor, InviteMembers $invite, RecordOnboardingProgress $progress, ProjectLookup $projects): RedirectResponse
    {
        $person = $this->currentPerson->find();
        abort_if($person === null, 403);

        $status = $this->state->for($person->id);
        abort_if($status->organizationId === null, 404);

        $project = $projects->oldest($status->organizationId);
        $emails = InviteMembersData::emailsFromText($request->string('emails')->toString());

        $result = $invite->handle($actor, new InviteMembersData(
            $status->organizationId,
            $emails,
            OrgRole::Member,
            $project === null ? [] : [new InviteGrant($project->id, ProjectRole::from($request->string('role')->toString()))],
        ));

        if ($result->created === [] && $result->rejected !== []) {
            throw ValidationException::withMessages(['emails' => implode(' ', array_map(
                static fn (string $email, string $reason): string => "{$email}: {$reason}.",
                array_keys($result->rejected),
                array_values($result->rejected),
            ))]);
        }

        $progress->handle($actor, RecordOnboardingProgressData::teamDone($status->organizationId));

        return $this->resume();
    }

    /** POST /onboarding/{organization}/team/skip */
    public function skipTeam(Actor $actor, RecordOnboardingProgress $progress): RedirectResponse
    {
        $person = $this->currentPerson->find();
        abort_if($person === null, 403);

        $status = $this->state->for($person->id);
        abort_if($status->organizationId === null, 404);

        $progress->handle($actor, RecordOnboardingProgressData::teamDone($status->organizationId));

        return $this->resume();
    }

    /** GET /onboarding/{organization}/ready */
    public function ready(Request $request): Response
    {
        return Inertia::render('onboarding/Ready', [
            'organization' => $this->organizationProps($request),
            'steps' => [
                'Create a flag',
                'Publish it to development',
                'Connect an SDK',
                'Verify it works',
            ],
        ]);
    }

    /** POST /onboarding/{organization}/ready */
    public function finish(Actor $actor, RecordOnboardingProgress $progress): RedirectResponse
    {
        $person = $this->currentPerson->find();
        abort_if($person === null, 403);

        $status = $this->state->for($person->id);
        abort_if($status->organizationId === null || $status->organizationSlug === null, 404);

        $progress->handle($actor, RecordOnboardingProgressData::readySeen($status->organizationId));

        return redirect()->route('organization.home', ['organization' => $status->organizationSlug]);
    }

    /** GET /onboarding/slug-availability?slug= */
    public function slugAvailability(Request $request, OrganizationSlugs $slugs): JsonResponse
    {
        $slug = strtolower(trim($request->string('slug')->toString()));
        $problem = $slug === '' ? null : $slugs->problem($slug);

        return new JsonResponse(['available' => $slug !== '' && $problem === null, 'reason' => $problem]);
    }

    /**
     * @return array{name: string, slug: string}
     */
    private function organizationProps(Request $request): array
    {
        $person = $this->currentPerson->find();
        abort_if($person === null, 403);

        $status = $this->state->for($person->id);
        $slug = $request->route('organization');

        abort_if(! is_string($slug) || $status->organizationSlug !== $slug, 404);

        return ['name' => (string) $status->organizationName, 'slug' => $slug];
    }
}
