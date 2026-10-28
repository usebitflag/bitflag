<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Actions\ConfirmTwoFactorEnrollment;
use App\Modules\Identity\Actions\DisableTwoFactor;
use App\Modules\Identity\Actions\RegenerateRecoveryCodes;
use App\Modules\Identity\Actions\StartTwoFactorEnrollment;
use App\Modules\Identity\Data\ConfirmTwoFactorEnrollmentData;
use App\Modules\Identity\Data\DisableTwoFactorData;
use App\Modules\Identity\Data\RegenerateRecoveryCodesData;
use App\Modules\Identity\Data\StartTwoFactorEnrollmentData;
use App\Modules\Identity\Queries\CurrentPerson;
use App\Modules\Identity\Support\QrCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Turning two-factor on and off and making recovery codes. These answer in JSON, and the codes appear only in that body:
 * never in the session, a flash message or a redirect. Responses are not cached.
 */
final class TwoFactorSettingsController
{
    public function start(Request $request, StartTwoFactorEnrollment $start, CurrentPerson $current, QrCode $qr): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'max:1000']]);

        $enrollment = $start->handle(new StartTwoFactorEnrollmentData($this->personId($current), $request->string('password')->toString()));

        return $this->json(['secret' => $enrollment->secret, 'uri' => $enrollment->uri, 'qr' => $qr->svg($enrollment->uri)]);
    }

    public function confirm(Request $request, ConfirmTwoFactorEnrollment $confirm, CurrentPerson $current): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:32']]);

        $codes = $confirm->handle(new ConfirmTwoFactorEnrollmentData(
            $this->personId($current),
            $request->string('code')->toString(),
            $request->ip(),
            $request->userAgent(),
        ));

        return $this->json(['recoveryCodes' => $codes]);
    }

    public function disable(Request $request, DisableTwoFactor $disable, CurrentPerson $current): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'max:1000'], 'code' => ['required', 'string', 'max:32']]);

        $disable->handle(new DisableTwoFactorData(
            $this->personId($current),
            $request->string('password')->toString(),
            $request->string('code')->toString(),
            $request->ip(),
            $request->userAgent(),
        ));

        return $this->json(['enabled' => false]);
    }

    public function regenerate(Request $request, RegenerateRecoveryCodes $regenerate, CurrentPerson $current): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'max:1000']]);

        $codes = $regenerate->handle(new RegenerateRecoveryCodesData($this->personId($current), $request->string('password')->toString()));

        return $this->json(['recoveryCodes' => $codes]);
    }

    private function personId(CurrentPerson $current): string
    {
        $person = $current->find();
        abort_if($person === null, 403);

        return $person->id;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function json(array $body): JsonResponse
    {
        return (new JsonResponse($body))->header('Cache-Control', 'no-store');
    }
}
