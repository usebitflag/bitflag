<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateOrganizationSecurityRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'require_mfa' => ['required', 'boolean'],
            'idle_timeout_minutes' => ['nullable', 'integer'],
            'allowed_email_domains' => ['present', 'array', 'max:50'],
            'allowed_email_domains.*' => ['string', 'max:253'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
