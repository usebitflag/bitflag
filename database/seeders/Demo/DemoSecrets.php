<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Credentials\Domain\TokenGenerator;

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

    /**
     * The demo's keys and tokens. Their secrets are made from fixed seeds, so the development stack and the end-to-end specs
     * can use them. They are test values that exist only where the demo seeder runs, never in production.
     *
     * @return array<string, string> a name to the full secret
     */
    public static function credentials(): array
    {
        $make = static function (string $prefix, string $seed): string {
            $body = $prefix.substr(hash('sha256', "bitflag-demo-{$seed}"), 0, 30);

            return $body.TokenGenerator::checksum($body);
        };

        return [
            'checkout_server_dev' => $make('bf_srv_dev_', 'checkout-server-dev'),
            'checkout_server_prod' => $make('bf_srv_prod_', 'checkout-server-prod'),
            'storefront_browser' => $make('bf_pub_prod_', 'storefront-browser'),
            'release_pipeline' => $make('bf_mgt_dev_', 'release-pipeline'),
            'legacy_mobile_app' => $make('bf_srv_prod_', 'legacy-mobile-app'),
            'maya_storefront_token' => $make('bf_pat_', 'maya-storefront-token'),
            'maya_checkout_token' => $make('bf_pat_', 'maya-checkout-token'),
        ];
    }

    /** The link token of the invitation for dev@northwind.studio to join Storefront as a Developer. */
    public const INVITATION_TOKEN = 'demo-invitation-token-for-the-e2e-specs_012';
}
