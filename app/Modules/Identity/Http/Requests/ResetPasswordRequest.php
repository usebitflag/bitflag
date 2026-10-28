<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResetPasswordRequest extends FormRequest
{
    /**
     * The password rules are enforced by the action, which knows the account's email.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:200'],
            'user' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string', 'max:1000'],
        ];
    }
}
