<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class IssuePersonalAccessTokenRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:60'],
            'access' => ['required', 'string', 'max:20'],
            'ttl_days' => ['nullable', 'integer'],
            // Making a token is a sensitive step, so the person confirms their password first.
            'password' => ['required', 'string', 'current_password:web'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['password.current_password' => 'That is not your password.'];
    }
}
