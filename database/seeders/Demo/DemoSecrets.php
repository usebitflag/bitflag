<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * Values the demo and the end-to-end specs agree on. They are public test values, never real secrets: the authenticator
 * secret and recovery codes of the demo people who use two-factor, and the link of the invitation waiting in the demo.
 */
final class DemoSecrets
{
    /** The shared secret an authenticator app would hold for Maya and Jon. */
    public const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    /** Recovery codes of the demo people who use two-factor. */
    public const RECOVERY_CODES = [
        'abcde-fghij', 'klmno-pqrst', 'uvwxy-zabcd', 'efghi-jklmn', 'opqrs-tuvwx',
        'yzabc-defgh', 'ijklm-nopqr', 'stuvw-xyzab', 'cdefg-hijkl', 'mnopq-rstuv',
    ];

    /** The link token of the invitation for dev@northwind.studio to join Storefront as a Developer. */
    public const INVITATION_TOKEN = 'demo-invitation-token-for-the-e2e-specs_012';
}
