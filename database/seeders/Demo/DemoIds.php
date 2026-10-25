<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * Fixed identifiers for the demo world, so a seeded database always has the same IDs and re-seeding updates rows
 * instead of adding new ones. They are valid lowercase ULIDs: "0100", a two-digit kind and a twenty-digit sequence.
 */
final class DemoIds
{
    private const ORGANIZATION = 1;

    private const PROJECT = 2;

    private const ENVIRONMENT = 3;

    private const USER = 4;

    private const ORGANIZATION_MEMBERSHIP = 5;

    private const PROJECT_MEMBERSHIP = 6;

    private const STAFF = 7;

    private const POLICY = 8;

    private const INVITATION = 9;

    public static function organization(int $number): string
    {
        return self::make(self::ORGANIZATION, $number);
    }

    public static function project(int $number): string
    {
        return self::make(self::PROJECT, $number);
    }

    public static function environment(int $project, int $position): string
    {
        return self::make(self::ENVIRONMENT, $project * 10 + $position);
    }

    public static function user(int $number): string
    {
        return self::make(self::USER, $number);
    }

    public static function organizationMembership(int $number): string
    {
        return self::make(self::ORGANIZATION_MEMBERSHIP, $number);
    }

    public static function projectMembership(int $number): string
    {
        return self::make(self::PROJECT_MEMBERSHIP, $number);
    }

    public static function policy(int $project, int $position): string
    {
        return self::make(self::POLICY, $project * 10 + $position);
    }

    public static function invitation(int $number): string
    {
        return self::make(self::INVITATION, $number);
    }

    public static function staff(int $number): string
    {
        return self::make(self::STAFF, $number);
    }

    private static function make(int $kind, int $number): string
    {
        return sprintf('0100%02d%020d', $kind, $number);
    }
}
