<?php

declare(strict_types=1);

use App\Modules\Environments\Domain\EnvironmentColor;
use App\Modules\Environments\Domain\EnvironmentStatus;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\MfaFactorType;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Organizations\Domain\OrganizationStatus;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Support\Domain\StaffStatus;
use App\Shared\Idempotency\IdempotencyStatus;

// These values are stored in the database. Changing or removing one needs a data migration, so a change here must be
// deliberate.
it('keeps the stored values of status and color enums', function (string $enum, array $values) {
    expect(array_map(static fn (BackedEnum $case): string|int => $case->value, $enum::cases()))->toBe($values);
})->with([
    'organization status' => [OrganizationStatus::class, ['active', 'suspended', 'deletion_scheduled', 'deleted']],
    'project status' => [ProjectStatus::class, ['active', 'archived', 'deletion_scheduled', 'deleted']],
    'environment status' => [EnvironmentStatus::class, ['active', 'archived']],
    'environment color' => [EnvironmentColor::class, ['slate', 'lilac', 'violet', 'indigo', 'ink']],
    'account status' => [AccountStatus::class, ['active', 'deactivated']],
    'mfa factor type' => [MfaFactorType::class, ['totp']],
    'security event type' => [SecurityEventType::class, [
        'signin.succeeded', 'signin.failed', 'signin.locked', 'mfa.challenge_failed', 'mfa.recovery_code_used',
        'mfa.enabled', 'mfa.disabled', 'password.reset', 'password.changed', 'email.changed', 'session.revoked', 'signup.provider',
    ]],
    'staff status' => [StaffStatus::class, ['active', 'disabled']],
    'idempotency status' => [IdempotencyStatus::class, ['in_progress', 'completed']],
]);
