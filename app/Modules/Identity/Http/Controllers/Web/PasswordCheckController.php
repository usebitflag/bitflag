<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Queries\PasswordStrength;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Answers only whether a password is on the local common list, for the live checklist while typing. The list never leaves
 * the server, and the answer carries nothing else.
 */
final class PasswordCheckController
{
    public function __invoke(Request $request, PasswordStrength $strength): JsonResponse
    {
        $password = $request->input('password');

        return new JsonResponse(['common' => is_string($password) && $strength->isCommon($password)]);
    }
}
