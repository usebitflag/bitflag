<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class IssueCredentialRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:32'],
            'label' => ['required', 'string', 'max:60'],
            'scopes' => ['required', 'array', 'max:10'],
            'scopes.*' => ['string', 'max:40'],
            'ttl_days' => ['nullable', 'integer'],
        ];
    }
}
