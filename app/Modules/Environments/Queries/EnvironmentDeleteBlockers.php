<?php

declare(strict_types=1);

namespace App\Modules\Environments\Queries;

use App\Modules\Credentials\Queries\EnvironmentCredentials;
use App\Modules\Environments\Models\Environment;

/**
 * What keeps an environment from being deleted. An environment that was published, or that ever had a key, can only be
 * archived, because SDKs, history and audit entries refer to it. Runs inside the organization's tenant context.
 */
final class EnvironmentDeleteBlockers
{
    public function __construct(private readonly EnvironmentCredentials $credentials) {}

    /**
     * @return list<string> a sentence for each blocker; empty when the environment can be deleted
     */
    public function for(string $environmentId): array
    {
        $environment = Environment::query()->find($environmentId);
        $blockers = [];

        if ($environment?->first_published_at !== null) {
            $blockers[] = 'Published revisions';
        }

        $working = count($this->credentials->workingIds($environmentId));

        if ($working > 0) {
            $blockers[] = $working === 1 ? '1 active SDK key' : "{$working} active SDK keys";
        }

        if ($this->credentials->hasAny($environmentId)) {
            $blockers[] = 'Credential history for this environment';
        }

        return $blockers;
    }
}
