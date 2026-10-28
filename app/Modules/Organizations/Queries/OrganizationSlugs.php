<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Organizations\Domain\SlugRules;
use App\Modules\Organizations\Models\Organization;

/**
 * Answers whether an organization URL name can be used, and what to suggest, for the screens that ask while a person types.
 */
final class OrganizationSlugs
{
    /**
     * Why the name cannot be used, or null when it can. A taken name and a reserved word read alike to the person.
     */
    public function problem(string $slug, ?string $exceptOrganizationId = null): ?string
    {
        $slug = strtolower(trim($slug));
        $problem = SlugRules::problem($slug);

        if ($problem !== null) {
            return $problem;
        }

        $taken = Organization::query()
            ->where('slug', $slug)
            ->when($exceptOrganizationId !== null, fn ($query) => $query->whereKeyNot($exceptOrganizationId))
            ->exists();

        return $taken ? SlugRules::TAKEN : null;
    }

    /**
     * The first free name made from the organization's name: "acme", then "acme-2", "acme-3".
     */
    public function suggest(string $name): string
    {
        $base = SlugRules::suggest($name);

        if (strlen($base) < 3) {
            $base = 'team';
        }

        for ($attempt = 1; $attempt <= 50; $attempt++) {
            $candidate = $attempt === 1 ? $base : substr($base, 0, 37).'-'.$attempt;

            if ($this->problem($candidate) === null) {
                return $candidate;
            }
        }

        return $base.'-'.bin2hex(random_bytes(2));
    }

    /**
     * The route constraint for the organization segment of a portal address.
     */
    public function routePattern(): string
    {
        return SlugRules::routePattern();
    }
}
