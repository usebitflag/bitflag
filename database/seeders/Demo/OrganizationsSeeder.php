<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Organizations\Domain\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Acme is the main demo organization. Globex exists so isolation checks have a second tenant. Maya Studio is Maya's own
 * organization.
 */
final class OrganizationsSeeder extends Seeder
{
    public const ACME = 1;

    public const GLOBEX = 2;

    /** Maya's own organization, so she belongs to two (the organization chooser). */
    public const STUDIO = 3;

    public function run(): void
    {
        foreach ([self::ACME => ['Acme', 'acme'], self::GLOBEX => ['Globex', 'globex'], self::STUDIO => ['Maya Studio', 'maya-studio']] as $number => [$name, $slug]) {
            $organization = Organization::query()->find(DemoIds::organization($number)) ?? new Organization;
            $organization->forceFill(['id' => DemoIds::organization($number), 'name' => $name, 'slug' => $slug, 'status' => OrganizationStatus::Active, 'version' => 1])->save();
        }
    }
}
