<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AcceptInvitationRequest extends FormRequest
{
    /**
     * A signed-in person sends nothing; someone without an account sends a name and a password, which the action checks
     * against the password rules.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'password' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
